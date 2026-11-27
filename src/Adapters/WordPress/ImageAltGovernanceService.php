<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use InvalidArgumentException;
use LogicException;
use SiteManager\Core\Model\CapabilityGraph;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;
use SiteManager\Core\Planning\PlanFactory;
use SiteManager\Core\Support\CanonicalJson;

/** Explicitly edits the shared native attachment ALT property; never arbitrary metadata. */
final class ImageAltGovernanceService
{
    private const KEY = '_wp_attachment_image_alt';

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        if ('content.write' !== $request->capabilityId || 'content.image_alt' !== $request->operation
            || ! $request->write || 'explicit' !== $request->confirmationClass
            || array_diff(array_keys($request->payload), ['attachment_id','parent_id','alt'])) {
            throw new InvalidArgumentException('Unsupported image ALT request.');
        }
        if (! $graph->get('content.write')?->governable) { throw new LogicException('Content is not governable.'); }
        $id = $request->payload['attachment_id'] ?? null;
        $parent = $request->payload['parent_id'] ?? null;
        $alt = $request->payload['alt'] ?? null;
        if (! is_int($id) || $id < 1 || ! is_int($parent) || $parent < 1
            || ! is_string($alt) || strlen($alt) > 800 || sanitize_text_field($alt) !== $alt) {
            throw new InvalidArgumentException('Positive native IDs and plain text ALT required.');
        }
        $before = $this->state($id,$parent);
        if (null === $before) { throw new LogicException('Image ALT is not safely governable.'); }
        return (new PlanFactory())->create('wordpress-core',$snapshot->fingerprint,$request,[
            'target'=>['attachment_id'=>$id,'parent_id'=>$parent,'post_type'=>'attachment'],
            'fields'=>['alt'=>$alt],
            'precondition_fingerprint'=>$this->hash($before),
        ],['target'=>['alt'=>$before['alt']], 'before'=>$before],true);
    }

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult
    {
        $this->assertPlan($plan);
        if (! $confirmed) { return new ExecutionResult(false,'confirmation-required'); }
        $id = $plan->changes['target']['attachment_id'];
        $parent = $plan->changes['target']['parent_id'];
        $before = $this->state($id,$parent);
        if (null === $before || $this->hash($before) !== $plan->changes['precondition_fingerprint']) {
            return new ExecutionResult(false,'stale-plan');
        }
        $alt = $plan->changes['fields']['alt'];
        if ($before['alt'] !== $alt) { update_post_meta($id,self::KEY,wp_slash($alt)); }
        $after = $this->state($id,$parent);
        if (null === $after) { return new ExecutionResult(false,'post-apply-read-failed',['attachment_id'=>$id]); }
        return new ExecutionResult(true,'applied',[
            'attachment_id'=>$id,'after_fingerprint'=>$this->hash($after),
        ],$plan->id);
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        $this->assertPlan($plan);
        $id = $plan->changes['target']['attachment_id'];
        $parent = $plan->changes['target']['parent_id'];
        $after = $result->success ? $this->state($id,$parent) : null;
        $expected = $plan->snapshot['before'];
        $expected['alt'] = $plan->changes['fields']['alt'];
        $ok = null !== $after && $after === $expected
            && $this->hash($after) === ($result->data['after_fingerprint'] ?? '');
        return new VerificationResult($ok,$ok?'verified':'verification-mismatch',[
            'attachment_id'=>$id,'alt_verified'=>$ok,
        ]);
    }

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult
    {
        $this->assertPlan($plan);
        if (! $confirmed) { return new RollbackResult(false,'confirmation-required'); }
        if (! $plan->reversible || ! $result->success) { return new RollbackResult(false,'rollback-unavailable'); }
        $id = $plan->changes['target']['attachment_id'];
        $parent = $plan->changes['target']['parent_id'];
        $current = $this->state($id,$parent);
        if (null === $current || $this->hash($current) !== ($result->data['after_fingerprint'] ?? '')) {
            return new RollbackResult(false,'stale-rollback');
        }
        $before = $plan->snapshot['before'];
        if ($before['alt'] === null) { delete_post_meta($id,self::KEY); }
        elseif ($current['alt'] !== $before['alt']) { update_post_meta($id,self::KEY,wp_slash($before['alt'])); }
        $ok = $this->state($id,$parent) === $before;
        return new RollbackResult($ok,$ok?'rolled-back':'rollback-failed',['attachment_id'=>$id,'alt_restored'=>$ok]);
    }

    private function state(int $id,int $parentId): ?array
    {
        $image = get_post($id);
        $parent = get_post($parentId);
        if (! $image || ! $parent || 'attachment' !== $image->post_type || 'inherit' !== $image->post_status
            || (int) $image->post_parent !== $parentId || ! wp_attachment_is_image($id)
            || ! current_user_can('edit_post',$id) || ! current_user_can('edit_post',$parentId)
            || ! in_array($parent->post_type,['post','page'],true)
            || ! in_array($parent->post_status,['draft','pending','private','publish'],true)) { return null; }
        $values = get_post_meta($id,self::KEY,false);
        // Reject multiple or nonscalar ALT rows instead of destroying ambiguous metadata.
        if (! is_array($values) || count($values)>1 || (isset($values[0]) && ! is_string($values[0]))) { return null; }
        return [
            'attachment_id'=>$id,'parent_id'=>$parentId,'parent_status'=>(string) $parent->post_status,
            'parent_password_hash'=>hash('sha256',(string) $parent->post_password),
            'mime'=>(string) $image->post_mime_type,'modified'=>(string) $image->post_modified_gmt,
            'file_fingerprint'=>$this->hash(['file'=>get_post_meta($id,'_wp_attached_file',true),'metadata'=>wp_get_attachment_metadata($id)]),
            'alt'=>$values[0] ?? null,
        ];
    }

    private function hash(array $value): string { return hash('sha256',CanonicalJson::encode($value)); }
    private function assertPlan(Plan $plan): void
    {
        if ('wordpress-core' !== $plan->adapter || 'content.write' !== $plan->request->capabilityId
            || 'content.image_alt' !== $plan->request->operation) { throw new InvalidArgumentException('Not an image ALT plan.'); }
    }
}
