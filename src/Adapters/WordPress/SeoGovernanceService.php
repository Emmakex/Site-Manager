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

final class SeoGovernanceService
{
    private const OWNER = 'seo-geo-theme';
    private const FIELD_MAP = [
        'title' => 'post_title',
        'description' => 'post_excerpt',
    ];
    private const OWNED_FIELDS = ['post_title', 'post_excerpt'];
    private const POST_TYPES = ['post', 'page'];

    public function __construct(
        private readonly ?ContentRuntime $runtime = null,
        private readonly ?PlanFactory $plans = null,
    ) {}

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        $this->assertRequest($request, $graph);
        $postId = (int) ($request->payload['post_id'] ?? 0);
        if ($postId < 1) {
            throw new InvalidArgumentException('seo.update requires a positive post_id.');
        }

        $runtime = $this->runtime();
        $current = $runtime->read($postId);
        if (null === $current) {
            throw new InvalidArgumentException('WordPress SEO target was not found.');
        }
        $this->assertSupportedTarget($current);
        if (! $runtime->canEdit($postId)) {
            throw new LogicException('Current WordPress principal cannot edit this SEO target.');
        }

        $requested = $request->payload['fields'] ?? null;
        if (! is_array($requested) || [] === $requested) {
            throw new InvalidArgumentException('seo.update requires a non-empty fields object.');
        }

        $fields = $this->normalizeFields($requested, $runtime);
        $before = $this->ownedSnapshot($current);
        $precondition = $this->fingerprint($current);

        return ($this->plans ?? new PlanFactory())->create(
            adapter: 'seo-geo-theme-native',
            fingerprint: $snapshot->fingerprint,
            request: $request,
            changes: [
                'target'=>['post_id'=>$postId,'post_type'=>(string) $current['post_type']],
                'semantic_fields'=>$requested,
                'native_fields'=>$fields,
                'precondition_fingerprint'=>$precondition,
                'derived_outputs'=>['title-meta','open-graph','schema','canonical','robots','hreflang','sitemap'],
                'side_effects'=>[
                    'title'=>'Changes the native WordPress title used by the page and SEO runtime.',
                    'description'=>'Changes the native WordPress excerpt used by the SEO runtime and may be visible where the theme renders excerpts.',
                ],
            ],
            snapshot: [
                'target'=>$before,
                'target_fingerprint'=>$precondition,
            ],
            reversible: true,
        );
    }

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult
    {
        $this->assertPlan($plan);
        if (! $confirmed) {
            return new ExecutionResult(false, 'confirmation-required', ['plan_id'=>$plan->id]);
        }

        $postId = (int) ($plan->changes['target']['post_id'] ?? 0);
        $runtime = $this->runtime();
        if (! $runtime->canEdit($postId)) {
            return new ExecutionResult(false, 'permission-denied', ['post_id'=>$postId]);
        }
        $current = $runtime->read($postId);
        if (null === $current) {
            return new ExecutionResult(false, 'target-missing', ['post_id'=>$postId]);
        }
        if ($this->fingerprint($current) !== (string) ($plan->changes['precondition_fingerprint'] ?? '')) {
            return new ExecutionResult(false, 'stale-plan', [
                'post_id'=>$postId,
                'expected_fingerprint'=>(string) ($plan->changes['precondition_fingerprint'] ?? ''),
                'actual_fingerprint'=>$this->fingerprint($current),
            ]);
        }

        $fields = is_array($plan->changes['native_fields'] ?? null) ? $plan->changes['native_fields'] : [];
        $mutation = $runtime->update($postId, $fields);
        if (! $mutation['success']) {
            return new ExecutionResult(false, 'apply-failed', ['post_id'=>$postId,'error'=>$mutation['error']]);
        }

        $after = $runtime->read($postId);
        if (null === $after) {
            return new ExecutionResult(false, 'post-apply-read-failed', ['post_id'=>$postId]);
        }

        return new ExecutionResult(true, 'applied', [
            'plan_id'=>$plan->id,
            'post_id'=>$postId,
            'expected_fields'=>$fields,
            'after'=>$this->ownedSnapshot($after),
            'after_fingerprint'=>$this->fingerprint($after),
            'derived_outputs'=>$plan->changes['derived_outputs'] ?? [],
        ], $plan->id);
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        $this->assertPlan($plan);
        if (! $result->success) {
            return new VerificationResult(false, 'apply-not-successful', ['apply_status'=>$result->status]);
        }

        $postId = (int) ($plan->changes['target']['post_id'] ?? 0);
        $current = $this->runtime()->read($postId);
        if (null === $current) {
            return new VerificationResult(false, 'target-missing', ['post_id'=>$postId]);
        }

        $expected = is_array($plan->changes['native_fields'] ?? null) ? $plan->changes['native_fields'] : [];
        $mismatches = [];
        foreach ($expected as $field => $value) {
            if ((string) ($current[$field] ?? '') !== (string) $value) {
                $mismatches[$field] = ['expected'=>(string) $value,'actual'=>(string) ($current[$field] ?? '')];
            }
        }

        return new VerificationResult(
            [] === $mismatches,
            [] === $mismatches ? 'verified' : 'verification-mismatch',
            ['post_id'=>$postId,'mismatches'=>$mismatches,'fingerprint'=>$this->fingerprint($current)],
        );
    }

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult
    {
        $this->assertPlan($plan);
        if (! $confirmed) {
            return new RollbackResult(false, 'confirmation-required', ['plan_id'=>$plan->id]);
        }
        if (! $plan->reversible || ! $result->success) {
            return new RollbackResult(false, 'rollback-unavailable', ['plan_id'=>$plan->id]);
        }

        $postId = (int) ($plan->changes['target']['post_id'] ?? 0);
        $runtime = $this->runtime();
        if (! $runtime->canEdit($postId)) {
            return new RollbackResult(false, 'permission-denied', ['post_id'=>$postId]);
        }
        $current = $runtime->read($postId);
        if (null === $current) {
            return new RollbackResult(false, 'target-missing', ['post_id'=>$postId]);
        }

        $expectedAfter = (string) ($result->data['after_fingerprint'] ?? '');
        if ('' === $expectedAfter || $this->fingerprint($current) !== $expectedAfter) {
            return new RollbackResult(false, 'stale-rollback', [
                'post_id'=>$postId,
                'expected_fingerprint'=>$expectedAfter,
                'actual_fingerprint'=>$this->fingerprint($current),
            ]);
        }

        $before = is_array($plan->snapshot['target'] ?? null) ? $plan->snapshot['target'] : [];
        $restore = [];
        foreach (self::OWNED_FIELDS as $field) {
            if (array_key_exists($field, $before)) {
                $restore[$field] = (string) $before[$field];
            }
        }
        $mutation = $runtime->update($postId, $restore);
        if (! $mutation['success']) {
            return new RollbackResult(false, 'rollback-failed', ['post_id'=>$postId,'error'=>$mutation['error']]);
        }

        $after = $runtime->read($postId);
        $matches = null !== $after;
        if ($matches) {
            foreach ($restore as $field => $value) {
                if ((string) ($after[$field] ?? '') !== (string) $value) {
                    $matches = false;
                    break;
                }
            }
        }

        return new RollbackResult(
            $matches,
            $matches ? 'rolled-back' : 'rollback-verification-failed',
            ['post_id'=>$postId,'restored_fields'=>array_keys($restore)],
        );
    }

    private function assertRequest(OperationRequest $request, CapabilityGraph $graph): void
    {
        $capability = $graph->get('seo.write');
        if (null === $capability || ! $capability->governable || self::OWNER !== $capability->provider) {
            throw new LogicException('seo.write is not governable by the SEO/GEO Theme adapter for this installation.');
        }
        if ('seo.write' !== $request->capabilityId || 'seo.update' !== $request->operation || ! $request->write) {
            throw new InvalidArgumentException('Unsupported SEO/GEO Theme operation.');
        }
        if ('explicit' !== $request->confirmationClass) {
            throw new InvalidArgumentException('SEO writes require explicit confirmation.');
        }
    }

    private function assertPlan(Plan $plan): void
    {
        if ('seo-geo-theme-native' !== $plan->adapter
            || 'seo.write' !== $plan->request->capabilityId
            || 'seo.update' !== $plan->request->operation
        ) {
            throw new InvalidArgumentException('Plan is not an SEO/GEO Theme semantic SEO update.');
        }
    }

    /** @param array<string,mixed> $target */
    private function assertSupportedTarget(array $target): void
    {
        if (! in_array((string) ($target['post_type'] ?? ''), self::POST_TYPES, true)) {
            throw new InvalidArgumentException('Theme-native SEO governance currently supports native WordPress posts and pages.');
        }
    }

    /** @param array<string,mixed> $requested @return array<string,string> */
    private function normalizeFields(array $requested, ContentRuntime $runtime): array
    {
        $normalized = [];
        foreach ($requested as $name => $value) {
            if (! is_string($name) || ! isset(self::FIELD_MAP[$name])) {
                throw new InvalidArgumentException('Unsupported SEO field: ' . (string) $name);
            }
            $field = self::FIELD_MAP[$name];
            $sanitized = $runtime->sanitize($field, $value);
            if ('post_title' === $field && '' === trim($sanitized)) {
                throw new InvalidArgumentException('title cannot be empty.');
            }
            $normalized[$field] = $sanitized;
        }
        return $normalized;
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    private function ownedSnapshot(array $post): array
    {
        $snapshot = [
            'ID'=>(int) ($post['ID'] ?? 0),
            'post_type'=>(string) ($post['post_type'] ?? ''),
        ];
        foreach (self::OWNED_FIELDS as $field) {
            $snapshot[$field] = (string) ($post[$field] ?? '');
        }
        return $snapshot;
    }

    /** @param array<string,mixed> $post */
    private function fingerprint(array $post): string
    {
        $source = $this->ownedSnapshot($post);
        $source['post_modified_gmt'] = (string) ($post['post_modified_gmt'] ?? '');
        return hash('sha256', CanonicalJson::encode($source));
    }

    private function runtime(): ContentRuntime
    {
        return $this->runtime ?? new NativeContentRuntime();
    }
}
