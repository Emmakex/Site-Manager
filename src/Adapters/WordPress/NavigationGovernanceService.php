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

final class NavigationGovernanceService
{
    private const PROVIDERS = [
        NativeNavigationRuntime::CLASSIC,
        NativeNavigationRuntime::BLOCK,
    ];

    private const PUBLIC_FIELDS = [
        NativeNavigationRuntime::CLASSIC => ['title','url','description','attr_title','target','classes','xfn'],
        NativeNavigationRuntime::BLOCK => ['title','content','status'],
    ];

    private const RESTORE_FIELDS = [
        NativeNavigationRuntime::CLASSIC => ['title','url','description','attr_title','target','classes','xfn','menu_item_parent','menu_order','status'],
        NativeNavigationRuntime::BLOCK => ['title','content','status'],
    ];

    public function __construct(
        private readonly ?NavigationRuntime $runtime = null,
        private readonly ?PlanFactory $plans = null,
    ) {}

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        $this->assertRequest($request, $graph);
        $provider = (string) ($request->payload['provider'] ?? '');
        $targetId = (int) ($request->payload['target_id'] ?? 0);
        if (! in_array($provider, self::PROVIDERS, true) || $targetId < 1) {
            throw new InvalidArgumentException('navigation.update requires a supported provider and positive target_id.');
        }
        if (! $this->providerWasDiscovered($provider, $snapshot)) {
            throw new LogicException('Requested navigation provider was not discovered on this installation.');
        }

        $runtime = $this->runtime();
        $current = $runtime->read($provider, $targetId);
        if (null === $current) {
            throw new InvalidArgumentException('WordPress navigation target was not found.');
        }
        if (! $runtime->canEdit($provider, $targetId)) {
            throw new LogicException('Current WordPress principal cannot edit this navigation target.');
        }

        $requested = $request->payload['fields'] ?? null;
        if (! is_array($requested) || [] === $requested) {
            throw new InvalidArgumentException('navigation.update requires a non-empty fields object.');
        }
        $fields = $this->normalizeFields($provider, $requested, $current, $runtime);
        $before = $this->ownedSnapshot($provider, $current);
        $precondition = $this->fingerprint($provider, $current);

        return ($this->plans ?? new PlanFactory())->create(
            adapter: 'wordpress-core',
            fingerprint: $snapshot->fingerprint,
            request: $request,
            changes: [
                'target'=>['provider'=>$provider,'target_id'=>$targetId],
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

        $provider = (string) ($plan->changes['target']['provider'] ?? '');
        $targetId = (int) ($plan->changes['target']['target_id'] ?? 0);
        $runtime = $this->runtime();
        if (! $runtime->canEdit($provider, $targetId)) {
            return new ExecutionResult(false, 'permission-denied', ['provider'=>$provider,'target_id'=>$targetId]);
        }
        $current = $runtime->read($provider, $targetId);
        if (null === $current) {
            return new ExecutionResult(false, 'target-missing', ['provider'=>$provider,'target_id'=>$targetId]);
        }
        $actualFingerprint = $this->fingerprint($provider, $current);
        if ($actualFingerprint !== (string) ($plan->changes['precondition_fingerprint'] ?? '')) {
            return new ExecutionResult(false, 'stale-plan', [
                'provider'=>$provider,
                'target_id'=>$targetId,
                'expected_fingerprint'=>(string) ($plan->changes['precondition_fingerprint'] ?? ''),
                'actual_fingerprint'=>$actualFingerprint,
            ]);
        }

        $fields = is_array($plan->changes['fields'] ?? null) ? $plan->changes['fields'] : [];
        $mutation = $runtime->update($provider, $targetId, $fields);
        if (! $mutation['success']) {
            return new ExecutionResult(false, 'apply-failed', ['provider'=>$provider,'target_id'=>$targetId,'error'=>$mutation['error']]);
        }
        $after = $runtime->read($provider, $targetId);
        if (null === $after) {
            return new ExecutionResult(false, 'post-apply-read-failed', ['provider'=>$provider,'target_id'=>$targetId]);
        }

        return new ExecutionResult(
            true,
            'applied',
            [
                'plan_id'=>$plan->id,
                'provider'=>$provider,
                'target_id'=>$targetId,
                'expected_fields'=>$fields,
                'after'=>$this->ownedSnapshot($provider, $after),
                'after_fingerprint'=>$this->fingerprint($provider, $after),
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
        $provider = (string) ($plan->changes['target']['provider'] ?? '');
        $targetId = (int) ($plan->changes['target']['target_id'] ?? 0);
        $current = $this->runtime()->read($provider, $targetId);
        if (null === $current) {
            return new VerificationResult(false, 'target-missing', ['provider'=>$provider,'target_id'=>$targetId]);
        }
        $expected = is_array($plan->changes['fields'] ?? null) ? $plan->changes['fields'] : [];
        $mismatches = [];
        foreach ($expected as $field => $value) {
            if (! $this->valuesEqual($current[$field] ?? null, $value)) {
                $mismatches[$field] = ['expected'=>$value,'actual'=>$current[$field] ?? null];
            }
        }
        return new VerificationResult(
            [] === $mismatches,
            [] === $mismatches ? 'verified' : 'verification-mismatch',
            ['provider'=>$provider,'target_id'=>$targetId,'mismatches'=>$mismatches,'fingerprint'=>$this->fingerprint($provider, $current)],
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

        $provider = (string) ($plan->changes['target']['provider'] ?? '');
        $targetId = (int) ($plan->changes['target']['target_id'] ?? 0);
        $runtime = $this->runtime();
        if (! $runtime->canEdit($provider, $targetId)) {
            return new RollbackResult(false, 'permission-denied', ['provider'=>$provider,'target_id'=>$targetId]);
        }
        $current = $runtime->read($provider, $targetId);
        if (null === $current) {
            return new RollbackResult(false, 'target-missing', ['provider'=>$provider,'target_id'=>$targetId]);
        }
        $expectedAfter = (string) ($result->data['after_fingerprint'] ?? '');
        $actualFingerprint = $this->fingerprint($provider, $current);
        if ('' === $expectedAfter || $actualFingerprint !== $expectedAfter) {
            return new RollbackResult(false, 'stale-rollback', [
                'provider'=>$provider,
                'target_id'=>$targetId,
                'expected_fingerprint'=>$expectedAfter,
                'actual_fingerprint'=>$actualFingerprint,
            ]);
        }

        $before = is_array($plan->snapshot['target'] ?? null) ? $plan->snapshot['target'] : [];
        $restore = [];
        foreach (self::RESTORE_FIELDS[$provider] ?? [] as $field) {
            if (array_key_exists($field, $before)) {
                $restore[$field] = $before[$field];
            }
        }
        $mutation = $runtime->update($provider, $targetId, $restore);
        if (! $mutation['success']) {
            return new RollbackResult(false, 'rollback-failed', ['provider'=>$provider,'target_id'=>$targetId,'error'=>$mutation['error']]);
        }
        $afterRollback = $runtime->read($provider, $targetId);
        $matches = null !== $afterRollback;
        if ($matches) {
            foreach ($restore as $field => $value) {
                if (! $this->valuesEqual($afterRollback[$field] ?? null, $value)) {
                    $matches = false;
                    break;
                }
            }
        }
        return new RollbackResult(
            $matches,
            $matches ? 'rolled-back' : 'rollback-verification-failed',
            ['provider'=>$provider,'target_id'=>$targetId,'restored_fields'=>array_keys($restore)],
        );
    }

    private function assertRequest(OperationRequest $request, CapabilityGraph $graph): void
    {
        $capability = $graph->get('navigation.write');
        if (null === $capability || ! $capability->governable) {
            throw new LogicException('navigation.write is not governable for this installation.');
        }
        if ('navigation.write' !== $request->capabilityId || 'navigation.update' !== $request->operation || ! $request->write) {
            throw new InvalidArgumentException('Unsupported WordPress navigation operation.');
        }
        if ('explicit' !== $request->confirmationClass) {
            throw new InvalidArgumentException('WordPress navigation writes require explicit confirmation.');
        }
    }

    private function assertPlan(Plan $plan): void
    {
        if ('wordpress-core' !== $plan->adapter
            || 'navigation.write' !== $plan->request->capabilityId
            || 'navigation.update' !== $plan->request->operation
        ) {
            throw new InvalidArgumentException('Plan is not a WordPress Core navigation update.');
        }
    }

    private function providerWasDiscovered(string $provider, DiscoverySnapshot $snapshot): bool
    {
        foreach ((array) ($snapshot->inventory['navigation']['providers'] ?? []) as $row) {
            if ($provider === (string) ($row['id'] ?? '') && ! empty($row['actionable'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $requested @param array<string,mixed> $current @return array<string,mixed> */
    private function normalizeFields(string $provider, array $requested, array $current, NavigationRuntime $runtime): array
    {
        $allowed = self::PUBLIC_FIELDS[$provider] ?? [];
        $normalized = [];
        foreach ($requested as $field => $value) {
            if (! is_string($field) || ! in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported navigation field: ' . (string) $field);
            }
            if (NativeNavigationRuntime::CLASSIC === $provider && 'url' === $field && 'custom' !== (string) ($current['type'] ?? '')) {
                throw new InvalidArgumentException('URL can only be changed for custom classic-menu items.');
            }
            $normalized[$field] = $runtime->sanitize($provider, $field, $value);
        }
        if (array_key_exists('title', $normalized) && '' === trim((string) $normalized['title'])) {
            throw new InvalidArgumentException('Navigation title cannot be empty.');
        }
        if (NativeNavigationRuntime::CLASSIC === $provider && array_key_exists('url', $normalized) && '' === trim((string) $normalized['url'])) {
            throw new InvalidArgumentException('Custom navigation URL cannot be empty.');
        }
        return $normalized;
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function ownedSnapshot(string $provider, array $target): array
    {
        $snapshot = ['provider'=>$provider,'ID'=>(int) ($target['ID'] ?? 0)];
        if (NativeNavigationRuntime::CLASSIC === $provider) {
            foreach (['menu_id','type','object','object_id','title','url','description','attr_title','target','classes','xfn','menu_item_parent','menu_order','status'] as $field) {
                $snapshot[$field] = $target[$field] ?? (in_array($field, ['classes'], true) ? [] : '');
            }
        } else {
            foreach (['title','content','status'] as $field) {
                $snapshot[$field] = $target[$field] ?? '';
            }
        }
        return $snapshot;
    }

    /**
     * Fingerprints cover only fields Site Manager owns/restores. WordPress may refresh
     * post_modified_gmt for menu-item housekeeping between requests even when none of
     * those governed fields changed; treating that timestamp as owned state makes a
     * safe rollback fail spuriously. Human/provider edits to governed navigation fields
     * still change this fingerprint and remain stale-protected.
     *
     * @param array<string,mixed> $target
     */
    private function fingerprint(string $provider, array $target): string
    {
        return hash('sha256', CanonicalJson::encode($this->ownedSnapshot($provider, $target)));
    }

    private function valuesEqual(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual) || is_array($expected)) {
            return CanonicalJson::encode($actual) === CanonicalJson::encode($expected);
        }
        return (string) $actual === (string) $expected;
    }

    private function runtime(): NavigationRuntime
    {
        return $this->runtime ?? new NativeNavigationRuntime();
    }
}
