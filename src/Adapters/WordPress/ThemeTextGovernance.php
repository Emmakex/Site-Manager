<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;
use SiteManager\Core\Planning\PlanFactory;
use SiteManager\Core\Support\CanonicalJson;

/**
 * Bounded text-only changes to Theme-owned Corporate homepage slots.
 * Data and rollback snapshots stay in WordPress, not in GitHub.
 */
final class ThemeTextGovernance
{
    private const MODEL_ID = 'corporate-home-v1';
    private const MAX_FIELDS = 5;
    private const MAX_TEXT_LENGTH = 2000;

    /** @param array<string,mixed> $requested */
    public function plan(array $requested, string $reason): Plan
    {
        $state = $this->state();
        $this->assertCanEdit($state['post_id']);
        if ([] === $requested || count($requested) > self::MAX_FIELDS) {
            throw new InvalidArgumentException('Theme text plan requires one to five fields.');
        }

        $accepted = [];
        foreach ($requested as $key => $value) {
            $contract = $state['definitions'][$key] ?? null;
            if (! is_string($key) || ! is_array($contract)
                || ($contract['type'] ?? '') !== 'text'
                || ! empty($contract['requires_verification'])
                || ! is_string($value) || '' === trim($value)
                || mb_strlen($value, 'UTF-8') > self::MAX_TEXT_LENGTH
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)
                || strip_tags($value) !== $value) {
                throw new InvalidArgumentException('Theme text slot/value is unsupported or requires verified proof.');
            }
            if (($state['slots'][$key] ?? null) === $value) {
                throw new InvalidArgumentException('Theme text plan does not change the selected slot.');
            }
            $accepted[$key] = $value;
        }
        $newSlots = array_replace($state['slots'], $accepted);
        $snapshot = (new WordPressAdapter())->discover();
        $request = new OperationRequest(
            capabilityId: 'content.write',
            operation: 'theme.update',
            payload: ['fields'=>$accepted],
            write: true,
            riskClass: 'editorial-review',
            confirmationClass: 'explicit',
            reason: $reason,
        );

        return (new PlanFactory())->create(
            adapter: 'seo-geo-theme-semantic',
            fingerprint: $snapshot->fingerprint,
            request: $request,
            changes: [
                'target'=>['post_id'=>$state['post_id'], 'model_id'=>self::MODEL_ID],
                'fields'=>$accepted,
                'precondition_fingerprint'=>$state['fingerprint'],
                'after_model'=>[
                    'model_id'=>self::MODEL_ID,
                    'source'=>'site-manager-native-override',
                    'slots'=>$newSlots,
                ],
            ],
            snapshot: [
                'previous_override'=>$state['previous_override'],
                'original_model'=>$state['slots'],
                'original_fingerprint'=>$state['fingerprint'],
            ],
            reversible: true,
        );
    }

    public function apply(Plan $plan): ExecutionResult
    {
        $this->assertPlan($plan);
        $id = (int) $plan->changes['target']['post_id'];
        $this->assertCanEdit($id);
        $current = $this->state();
        if ($current['post_id'] !== $id
            || $current['fingerprint'] !== $plan->changes['precondition_fingerprint']) {
            return new ExecutionResult(false, 'stale-theme-model');
        }
        $after = $plan->changes['after_model'] ?? null;
        if (! is_array($after) || ! is_array($after['slots'] ?? null)
            || ($after['model_id'] ?? null) !== self::MODEL_ID) {
            return new ExecutionResult(false, 'invalid-theme-plan');
        }
        update_post_meta($id, ThemeModelOverride::META_KEY, $after);
        $readBack = $this->state();
        if ($readBack['post_id'] !== $id
            || $readBack['source'] !== 'site-manager-native-override') {
            return new ExecutionResult(false, 'theme-model-readback-failed');
        }
        foreach ($plan->changes['fields'] as $field=>$value) {
            if (($readBack['slots'][$field] ?? null) !== $value) {
                return new ExecutionResult(false, 'theme-model-readback-mismatch');
            }
        }
        return new ExecutionResult(true, 'applied', [
            'post_id'=>$id,
            'changed_slot_ids'=>array_keys($plan->changes['fields']),
            'after_fingerprint'=>$readBack['fingerprint'],
        ], $plan->id);
    }

    public function verify(Plan $plan, ExecutionResult $execution): VerificationResult
    {
        $this->assertPlan($plan);
        if (! $execution->success) {
            return new VerificationResult(false, 'apply-not-successful');
        }
        $state = $this->state();
        $ok = $state['post_id'] === (int) $plan->changes['target']['post_id']
            && $state['fingerprint'] === (string) ($execution->data['after_fingerprint'] ?? '')
            && $state['source'] === 'site-manager-native-override';
        foreach ($plan->changes['fields'] as $field => $value) {
            $ok = $ok && ($state['slots'][$field] ?? null) === $value;
        }
        return new VerificationResult($ok, $ok ? 'verified' : 'theme-model-mismatch', [
            'post_id'=>$state['post_id'],
            'verified_slot_ids'=>array_keys($plan->changes['fields']),
            'fingerprint'=>$state['fingerprint'],
        ]);
    }

    public function rollback(Plan $plan, ExecutionResult $execution): RollbackResult
    {
        $this->assertPlan($plan);
        if (! $plan->reversible || ! $execution->success) {
            return new RollbackResult(false, 'rollback-unavailable');
        }
        $id = (int) $plan->changes['target']['post_id'];
        $this->assertCanEdit($id);
        $state = $this->state();
        if ($state['post_id'] !== $id
            || $state['fingerprint'] !== (string) ($execution->data['after_fingerprint'] ?? '')) {
            return new RollbackResult(false, 'stale-theme-rollback');
        }
        $original = $plan->snapshot['previous_override'] ?? null;
        if (is_array($original)) {
            update_post_meta($id, ThemeModelOverride::META_KEY, $original);
        } else {
            delete_post_meta($id, ThemeModelOverride::META_KEY);
        }
        $readBack = $this->state();
        $restored = $readBack['fingerprint'] === $plan->snapshot['original_fingerprint']
            && $readBack['slots'] === $plan->snapshot['original_model'];
        return new RollbackResult($restored, $restored ? 'rolled-back' : 'theme-rollback-verification-failed', [
            'post_id'=>$id,
            'restored_slot_ids'=>array_keys($plan->changes['fields']),
        ]);
    }

    /** @return array{post_id:int,source:string,slots:array<string,mixed>,definitions:array<string,array<string,mixed>>,previous_override:mixed,fingerprint:string} */
    public function state(): array
    {
        if (! function_exists('seo_geo_theme_preset_document')
            || ! class_exists('SeoGeo\\Theme\\Strategic\\CorporateHomeModelResolver')) {
            throw new LogicException('Active SEO/GEO Theme semantic model is not available.');
        }
        $id = (int) get_option('page_on_front', 0);
        $post = $id > 0 ? get_post($id) : null;
        if (! is_object($post) || (int) ($post->ID ?? 0) !== $id
            || (string) ($post->post_type ?? '') !== 'page'
            || (string) ($post->post_status ?? '') !== 'publish') {
            throw new LogicException('Theme model can only change the published front page.');
        }

        $doc = seo_geo_theme_preset_document('corporate', 'content-map.json');
        $contract = is_array($doc) ? ($doc['native_content_models'][self::MODEL_ID] ?? null) : null;
        $defs = is_array($contract) ? ($contract['slots'] ?? null) : null;
        if (! is_array($defs) || [] === $defs || count($defs) > 100) {
            throw new LogicException('Theme semantic contract is unavailable.');
        }
        $definitions = [];
        foreach ($defs as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)
                || ! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $item['id'])
                || ! in_array($item['type'] ?? null, ['text','link','list'], true)
                || isset($definitions[$item['id']])) {
                throw new LogicException('Theme slot definition is unsupported.');
            }
            $definitions[$item['id']] = $item;
        }
        $model = (new \SeoGeo\Theme\Strategic\CorporateHomeModelResolver())->resolve($post);
        if (! is_array($model) || ($model['model_id'] ?? null) !== self::MODEL_ID
            || ! is_array($model['slots'] ?? null)
            || array_diff(array_keys($model['slots']), array_keys($definitions)) !== []) {
            throw new LogicException('Current Theme model is missing or incompatible.');
        }
        $source = (string) ($model['source'] ?? '');
        if (! in_array($source, ['hydrated-semantic-blocks', 'site-manager-native-override'], true)) {
            throw new LogicException('Theme model is governed by another provider.');
        }
        foreach ($definitions as $name=>$definition) {
            if (true === ($definition['required'] ?? false) && ! array_key_exists($name, $model['slots'])) {
                throw new LogicException('Required Theme model data is absent.');
            }
        }

        $prior = get_post_meta($id, ThemeModelOverride::META_KEY, true);
        $previousOverride = is_array($prior) ? $prior : null;
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'site_url'=>home_url('/'),
            'front_page_id'=>$id,
            'modified_gmt'=>(string) ($post->post_modified_gmt ?? ''),
            'model_source'=>$source,
            'slots'=>$model['slots'],
            'previous_override'=>$previousOverride,
        ]));
        return [
            'post_id'=>$id,
            'source'=>$source,
            'slots'=>$model['slots'],
            'definitions'=>$definitions,
            'previous_override'=>$previousOverride,
            'fingerprint'=>$fingerprint,
        ];
    }

    private function assertCanEdit(int $postId): void
    {
        if ($postId < 1 || ! function_exists('current_user_can')
            || ! current_user_can('edit_post', $postId)) {
            throw new LogicException('WordPress administrator cannot edit the Theme homepage.');
        }
    }

    private function assertPlan(Plan $plan): void
    {
        if ($plan->adapter !== 'seo-geo-theme-semantic'
            || $plan->request->capabilityId !== 'content.write'
            || $plan->request->operation !== 'theme.update'
            || $plan->request->confirmationClass !== 'explicit') {
            throw new InvalidArgumentException('Only explicitly confirmed Theme semantic plans may execute.');
        }
    }
}
