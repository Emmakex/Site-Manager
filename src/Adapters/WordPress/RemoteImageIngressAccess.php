<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\VerificationResult;
use SiteManager\Core\Support\CanonicalJson;

/**
 * Manifest-only planning followed by an explicitly confirmed exact-byte apply.
 * No binary bytes in plan storage, no arbitrary URLs, no public staging files.
 * There is deliberately no automatic deletion rollback: uploaded files may
 * already be shared elsewhere. A partial write requires reconciliation.
 */
final class RemoteImageIngressAccess
{
    public static function registerRest(): void
    {
        foreach (['plan','apply'] as $stage) {
            register_rest_route('site-manager/v1','/write/content/image-upload/'.$stage,[
                'methods'=>\WP_REST_Server::CREATABLE,
                'permission_callback'=>static fn(\WP_REST_Request $r):bool=>RemoteWriteAccess::authorizeWriteRequest($r,'content.write'),
                'callback'=>[self::class,$stage],
            ],true);
        }
    }

    public static function plan(\WP_REST_Request $request): \WP_REST_Response
    {
        $p = $request->get_json_params();
        if (! is_array($p) || count($p)!==2 || ! is_int($p['parent_id'] ?? null) || $p['parent_id']<1 || ! is_array($p['manifest'] ?? null)) {
            return self::error(400,'invalid_image_upload_plan');
        }
        try {
            $manifest = (new ImageIngressGuard())->manifest($p['manifest']);
            $runtime = new NativeImageIngressRuntime();
            $target = $runtime->target($p['parent_id']);
            if (null===$target || ! function_exists('imagecreatefromstring')) { return self::error(409,'image_upload_not_governable'); }
            $adapter = new WordPressAdapter();
            $snapshot = $adapter->discover();
            if ($adapter->capabilities($snapshot)->get('content.write')?->governable!==true) {
                return self::error(409,'image_upload_not_governable');
            }
            [$connection,$generation] = self::identity();
            if ($connection==='' || $generation<1) { return self::error(409,'connection_unavailable'); }
            // Random ID distinguishes new, explicitly reviewed upload attempts.
            $plan = new Plan(
                id:'sm-plan-'.bin2hex(random_bytes(12)),
                adapter:'wordpress-image-ingress',
                fingerprint:$snapshot->fingerprint,
                request:new OperationRequest(capabilityId:'content.write',operation:'content.image_upload',
                    payload:['parent_id'=>$p['parent_id'],'manifest'=>$manifest],write:true,
                    riskClass:'editorial-review',confirmationClass:'explicit',
                    reason:'Approved image upload to native draft; public media URL possible'),
                changes:['target'=>['parent_id'=>$p['parent_id'],'post_type'=>$target['post_type']],
                    'fields'=>['manifest'=>$manifest],'parent_fingerprint'=>self::fingerprint($target)],
                snapshot:[],reversible:false,createdAt:gmdate(DATE_W3C),
            );
            $saved = (new RemotePlanStore())->save($plan,$connection,$generation);
            return self::response(200,['stage'=>'plan','status'=>'planned','plan'=>self::safeSummary($saved),
                'preview'=>['parent_id'=>$p['parent_id'],'manifest'=>$manifest,'byte_validation_pending'=>true,
                    'public_url_possible'=>true,'rollback_available'=>false,'confirmation_required'=>true]]);
        } catch (\InvalidArgumentException) {
            return self::error(400,'invalid_image_manifest');
        } catch (\Throwable) {
            return self::error(500,'image_upload_plan_failed');
        }
    }

    public static function apply(\WP_REST_Request $request): \WP_REST_Response
    {
        $p = $request->get_json_params();
        if (! is_array($p) || count($p)!==3 || ($p['confirmed'] ?? null)!==true
            || ! is_string($p['plan_id'] ?? null) || ! preg_match('/^sm-plan-[a-f0-9]{24}$/D',$p['plan_id'])
            || ! is_string($p['image_base64'] ?? null) || strlen($p['image_base64'])>2_666_668) {
            return self::error(400,'invalid_image_upload_apply');
        }
        [$connection,$generation] = self::identity();
        $plans = new RemotePlanStore();
        $plan = $plans->pending($p['plan_id'],$connection,$generation);
        if (! $plan instanceof Plan || $plan->adapter!=='wordpress-image-ingress'
            || $plan->request->operation!=='content.image_upload' || $plan->request->capabilityId!=='content.write'
            || $plan->request->confirmationClass!=='explicit' || $plan->reversible) {
            return self::error(409,'image_upload_plan_unavailable');
        }
        $parent = $plan->request->payload['parent_id'];
        $manifest = $plan->request->payload['manifest'];
        $runtime = new NativeImageIngressRuntime();

        // Serialize attempts by one database-owned option. No automatic expiry:
        // interruption after creation is ambiguous, not permission to retry.
        $lock = 'site_manager_image_apply_'.substr(hash('sha256',$plan->id),0,32);
        if (! add_option($lock,'locked','',false)) { return self::error(409,'image_upload_reconciliation_required'); }
        $mutationStarted = false;
        try {
            $fresh = $plans->pending($plan->id,$connection,$generation);
            $state = $runtime->target($parent);
            if (null===$fresh || null===$state || self::fingerprint($state)!==$plan->changes['parent_fingerprint']
                || (new WordPressAdapter())->discover()->fingerprint!==$plan->fingerprint) {
                $plans->markRejected($plan,'stale-image-upload-plan');
                return self::error(409,'stale_image_upload_plan');
            }
            // Verify bytes before entering the mutation boundary.
            (new ImageIngressGuard())->decode($manifest,$p['image_base64']);
            // Persist an in-progress rejection before invoking the writer.
            // If PHP dies, the same plan is no longer pending.
            $claimed = $plans->markRejected($plan,'image-upload-in-progress');
            if (($claimed['status'] ?? '')!=='image-upload-in-progress'
                || null!==$plans->pending($plan->id,$connection,$generation)) {
                return self::error(409,'image_upload_claim_failed');
            }
            $mutationStarted = true;
            $result = $runtime->create($parent,$manifest,$p['image_base64'],$plan->id,true);
            $verified = ($result['verified'] ?? false)===true;
            $execution = new ExecutionResult($verified,$verified?'applied':'requires-reconciliation',$result,$plan->id);
            $verification = new VerificationResult($verified,$verified?'verified':'requires-reconciliation',[
                'attachment_id'=>$result['attachment_id'] ?? null,'parent_id'=>$parent,
                'stored_sha256'=>$result['stored_sha256'] ?? null,'verified'=>$verified,
            ]);
            $saved = $plans->markApplied($plan,$execution,$verification);
            if (($saved['status'] ?? '')!==($verified?'applied':'apply-failed')) {
                return self::response(409,['stage'=>'apply','status'=>'requires-reconciliation',
                    'attachment_id'=>$result['attachment_id'] ?? null,'verified'=>false,'rollback_available'=>false]);
            }
            return self::response($verified?200:409,['stage'=>'apply','status'=>$verified?'verified':'requires-reconciliation',
                'plan'=>self::safeSummary($saved),'result'=>$result,'parent_id'=>$parent,
                'verified'=>$verified,'rollback_available'=>false]);
        } catch (\InvalidArgumentException) {
            return self::error($mutationStarted?409:400,$mutationStarted?'image_upload_reconciliation_required':'invalid_image_bytes');
        } catch (\Throwable) {
            return self::error($mutationStarted?409:500,$mutationStarted?'image_upload_reconciliation_required':'image_upload_apply_failed');
        } finally {
            // The plan is consumed at the mutation boundary. Keep an ambiguous
            // claim locked; a privileged reconciliation must resolve it.
            if (! $mutationStarted || (isset($saved) && ($saved['status'] ?? '')==='applied')) { delete_option($lock); }
        }
    }

    private static function identity(): array
    {
        $c = (new RemoteConnectionStore())->publicState();
        return [(string)($c['connection_id'] ?? ''),(int)($c['generation'] ?? 0)];
    }

    private static function fingerprint(array $target): string
    {
        return hash('sha256',CanonicalJson::encode($target));
    }

    private static function safeSummary(array $summary): array
    {
        return array_intersect_key($summary,array_flip(['plan_id','status','capability_id','operation','target','fields',
            'confirmation_required','reversible','created_at','expires_at','applied_at']));
    }

    private static function response(int $code,array $data): \WP_REST_Response
    {
        return new \WP_REST_Response(['data'=>$data],$code);
    }
    private static function error(int $code,string $error): \WP_REST_Response
    {
        return new \WP_REST_Response(['error'=>['code'=>$error]],$code);
    }
}
