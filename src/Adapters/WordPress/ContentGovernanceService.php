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

final class ContentGovernanceService
{
    private const FIELD_MAP = [
        'title'=>'post_title',
        'slug'=>'post_name',
        'content'=>'post_content',
        'excerpt'=>'post_excerpt',
        'status'=>'post_status',
    ];

    private const OWNED_FIELDS = [
        'post_title',
        'post_name',
        'post_content',
        'post_excerpt',
        'post_status',
    ];

    private const POST_TYPES = ['post', 'page'];
    private const STATUSES = ['draft', 'publish', 'private', 'pending'];

    public function __construct(
        private readonly ?ContentRuntime $runtime = null,
        private readonly ?PlanFactory $plans = null,
    ) {}

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        $this->assertRequest($request, $graph);
        $postId = (int) ($request->payload['post_id'] ?? 0);
        if ($postId < 1) {
            throw new InvalidArgumentException('content.update requires a positive post_id.');
        }

        $runtime = $this->runtime();
        $current = $runtime->read($postId);
        if (null === $current) {
            throw new InvalidArgumentException('WordPress content target was not found.');
        }
        $this->assertSupportedTarget($current);
        if (! $runtime->canEdit($postId)) {
            throw new LogicException('Current WordPress principal cannot edit this content target.');
        }

        $requested = $request->payload['fields'] ?? null;
        if (! is_array($requested) || [] === $requested) {
            throw new InvalidArgumentException('content.update requires a non-empty fields object.');
        }
        $fields = $this->normalizeFields($requested, $runtime);
        $before = $this->ownedSnapshot($current);
        $precondition = $this->fingerprint($current);

        return ($this->plans ?? new PlanFactory())->create(
            adapter: 'wordpress-core',
            fingerprint: $snapshot->fingerprint,
            request: $request,
            changes: [
                'target'=>['post_id'=>$postId,'post_type'=>(string) $current['post_type']],
                'fields'=>$fields,
                'precondition_fingerprint'=>$precondition,
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

        $fields = is_array($plan->changes['fields'] ?? null) ? $plan->changes['fields'] : [];
        $mutation = $runtime->update($postId, $fields);
        if (! $mutation['success']) {
            return new ExecutionResult(false, 'apply-failed', ['post_id'=>$postId,'error'=>$mutation['error']]);
        }

        $after = $runtime->read($postId);
        if (null === $after) {
            return new ExecutionResult(false, 'post-apply-read-failed', ['post_id'=>$postId]);
        }

        return new ExecutionResult(
            true,
            'applied',
            [
                'plan_id'=>$plan->id,
                'post_id'=>$postId,
                'expected_fields'=>$fields,
                'after'=>$this->ownedSnapshot($after),
                'after_fingerprint'=>$this->fingerprint($after),
            ],
            $plan->id,
        );
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

        $expected = is_array($plan->changes['fields'] ?? null) ? $plan->changes['fields'] : [];
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

        $afterRollback = $runtime->read($postId);
        $matches = null !== $afterRollback;
        if ($matches) {
            foreach ($restore as $field => $value) {
                if ((string) ($afterRollback[$field] ?? '') !== $value) {
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
        $capability = $graph->get('content.write');
        if (null === $capability || ! $capability->governable) {
            throw new LogicException('content.write is not governable for this installation.');
        }
        if ('content.write' !== $request->capabilityId || 'content.update' !== $request->operation || ! $request->write) {
            throw new InvalidArgumentException('Unsupported WordPress content operation.');
        }
        if ('explicit' !== $request->confirmationClass) {
            throw new InvalidArgumentException('WordPress content writes require explicit confirmation.');
        }
    }

    private function assertPlan(Plan $plan): void
    {
        if ('wordpress-core' !== $plan->adapter
            || 'content.write' !== $plan->request->capabilityId
            || 'content.update' !== $plan->request->operation
        ) {
            throw new InvalidArgumentException('Plan is not a WordPress Core content update.');
        }
    }

    /** @param array<string,mixed> $target */
    private function assertSupportedTarget(array $target): void
    {
        if (! in_array((string) ($target['post_type'] ?? ''), self::POST_TYPES, true)) {
            throw new InvalidArgumentException('SM-2A only governs native WordPress posts and pages.');
        }
    }

    /** @param array<string,mixed> $requested @return array<string,string> */
    private function normalizeFields(array $requested, ContentRuntime $runtime): array
    {
        $normalized = [];
        foreach ($requested as $name => $value) {
            if (! is_string($name) || ! isset(self::FIELD_MAP[$name])) {
                throw new InvalidArgumentException('Unsupported content field: ' . (string) $name);
            }
            $field = self::FIELD_MAP[$name];
            $sanitized = $runtime->sanitize($field, $value);
            if ('post_status' === $field && ! in_array($sanitized, self::STATUSES, true)) {
                throw new InvalidArgumentException('Unsupported publication state.');
            }
            if (in_array($field, ['post_title','post_name'], true) && '' === trim($sanitized)) {
                throw new InvalidArgumentException($name . ' cannot be empty.');
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
