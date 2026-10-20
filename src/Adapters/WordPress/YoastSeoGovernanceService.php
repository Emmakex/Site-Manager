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

final class YoastSeoGovernanceService
{
    private const FIELD_MAP = [
        'title'=>'title',
        'description'=>'metadesc',
        'canonical'=>'canonical',
        'indexability'=>'meta-robots-noindex',
    ];
    private const OWNED_FIELDS = ['title','metadesc','canonical','meta-robots-noindex'];
    private const INDEXABILITY = ['default'=>'0','index'=>'2','noindex'=>'1'];

    public function __construct(
        private readonly ?YoastSeoRuntime $runtime = null,
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
        if (! $runtime->available()) {
            throw new LogicException('Yoast SEO metadata API is not available in the current runtime.');
        }
        $current = $runtime->read($postId);
        if (null === $current) {
            throw new InvalidArgumentException('Yoast SEO target was not found.');
        }
        $this->assertSupportedTarget($current, $snapshot);
        if (! $runtime->canEdit($postId)) {
            throw new LogicException('Current WordPress principal cannot edit this Yoast SEO target.');
        }

        $requested = $request->payload['fields'] ?? null;
        if (! is_array($requested) || [] === $requested) {
            throw new InvalidArgumentException('seo.update requires a non-empty fields object.');
        }
        $fields = $this->normalizeFields($requested);
        $before = $this->ownedSnapshot($current);
        $precondition = $this->fingerprint($current);

        return ($this->plans ?? new PlanFactory())->create(
            adapter: 'yoast-seo-meta-api',
            fingerprint: $snapshot->fingerprint,
            request: $request,
            changes: [
                'target'=>['post_id'=>$postId,'post_type'=>(string) ($current['post_type'] ?? '')],
                'semantic_fields'=>$requested,
                'provider_fields'=>$fields,
                'precondition_fingerprint'=>$precondition,
                'derived_outputs'=>['open-graph','schema','sitemap'],
                'side_effects'=>[
                    'title'=>'Overrides the SEO title used by Yoast for this target.',
                    'description'=>'Overrides the Yoast meta description for this target.',
                    'canonical'=>'Overrides the canonical URL used by Yoast for this target.',
                    'indexability'=>'Overrides Yoast index/noindex for this target; default returns control to the post-type default.',
                ],
            ],
            snapshot: ['target'=>$before,'target_fingerprint'=>$precondition],
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
        if (! $runtime->available()) {
            return new ExecutionResult(false, 'provider-api-unavailable', ['provider'=>'yoast-seo']);
        }
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

        $fields = is_array($plan->changes['provider_fields'] ?? null) ? $plan->changes['provider_fields'] : [];
        $mutation = $runtime->update($postId, $fields);
        if (empty($mutation['success'])) {
            return new ExecutionResult(false, 'apply-failed', ['post_id'=>$postId,'error'=>(string) ($mutation['error'] ?? '')]);
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
        $expected = is_array($plan->changes['provider_fields'] ?? null) ? $plan->changes['provider_fields'] : [];
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
        if (! $runtime->available()) {
            return new RollbackResult(false, 'provider-api-unavailable', ['provider'=>'yoast-seo']);
        }
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
        if (empty($mutation['success'])) {
            return new RollbackResult(false, 'rollback-failed', ['post_id'=>$postId,'error'=>(string) ($mutation['error'] ?? '')]);
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
        if (null === $capability || ! $capability->governable || 'yoast-seo' !== $capability->provider) {
            throw new LogicException('seo.write is not governable by the Yoast SEO adapter for this installation.');
        }
        if ('seo.write' !== $request->capabilityId || 'seo.update' !== $request->operation || ! $request->write) {
            throw new InvalidArgumentException('Unsupported Yoast SEO operation.');
        }
        if ('explicit' !== $request->confirmationClass) {
            throw new InvalidArgumentException('SEO writes require explicit confirmation.');
        }
    }

    private function assertPlan(Plan $plan): void
    {
        if ('yoast-seo-meta-api' !== $plan->adapter
            || 'seo.write' !== $plan->request->capabilityId
            || 'seo.update' !== $plan->request->operation
        ) {
            throw new InvalidArgumentException('Plan is not a Yoast SEO update.');
        }
    }

    /** @param array<string,mixed> $target */
    private function assertSupportedTarget(array $target, DiscoverySnapshot $snapshot): void
    {
        $postType = (string) ($target['post_type'] ?? '');
        $supported = [];
        foreach ((array) ($snapshot->inventory['content_types'] ?? []) as $type) {
            if (! is_array($type)) { continue; }
            $name = (string) ($type['name'] ?? '');
            if ('' !== $name && ! in_array($name, ['attachment','wp_navigation'], true)) {
                $supported[] = $name;
            }
        }
        if ('' === $postType || ! in_array($postType, $supported, true)) {
            throw new InvalidArgumentException('Yoast SEO governance only supports discovered public content types.');
        }
    }

    /** @param array<string,mixed> $requested @return array<string,string> */
    private function normalizeFields(array $requested): array
    {
        $normalized = [];
        foreach ($requested as $name => $value) {
            if (! is_string($name) || ! isset(self::FIELD_MAP[$name])) {
                throw new InvalidArgumentException('Unsupported Yoast SEO field: ' . (string) $name);
            }
            $internal = self::FIELD_MAP[$name];
            $value = is_scalar($value) ? (string) $value : '';
            if ('indexability' === $name) {
                $key = strtolower(trim($value));
                if (! isset(self::INDEXABILITY[$key])) {
                    throw new InvalidArgumentException('indexability must be default, index or noindex.');
                }
                $normalized[$internal] = self::INDEXABILITY[$key];
                continue;
            }
            if ('canonical' === $name) {
                $value = trim($value);
                if ('' !== $value) {
                    $sanitized = function_exists('esc_url_raw') ? (string) esc_url_raw($value) : filter_var($value, FILTER_SANITIZE_URL);
                    if ('' === $sanitized || false === filter_var($sanitized, FILTER_VALIDATE_URL)) {
                        throw new InvalidArgumentException('canonical must be an absolute valid URL or empty.');
                    }
                    $value = $sanitized;
                }
                $normalized[$internal] = $value;
                continue;
            }
            $sanitized = function_exists('sanitize_text_field') ? (string) sanitize_text_field($value) : trim(strip_tags($value));
            if ('title' === $name && '' === trim($sanitized)) {
                throw new InvalidArgumentException('title cannot be empty.');
            }
            $normalized[$internal] = $sanitized;
        }
        return $normalized;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function ownedSnapshot(array $row): array
    {
        $snapshot = [
            'ID'=>(int) ($row['ID'] ?? 0),
            'post_type'=>(string) ($row['post_type'] ?? ''),
        ];
        foreach (self::OWNED_FIELDS as $field) {
            $snapshot[$field] = (string) ($row[$field] ?? '');
        }
        return $snapshot;
    }

    /** @param array<string,mixed> $row */
    private function fingerprint(array $row): string
    {
        $source = $this->ownedSnapshot($row);
        $source['post_modified_gmt'] = (string) ($row['post_modified_gmt'] ?? '');
        return hash('sha256', CanonicalJson::encode($source));
    }

    private function runtime(): YoastSeoRuntime
    {
        return $this->runtime ?? new NativeYoastSeoRuntime();
    }
}
