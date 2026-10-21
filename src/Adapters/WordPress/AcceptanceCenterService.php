<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use RuntimeException;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;
use Throwable;

final class AcceptanceCenterService
{
    public const STATE_OPTION = 'site_manager_acceptance_run';
    public const HISTORY_OPTION = 'site_manager_acceptance_history';
    public const LOCK_OPTION = 'site_manager_acceptance_lock';
    public const PAUSE_MS = 2000;
    private const LOCK_TTL_SECONDS = 90;
    private const HISTORY_LIMIT = 10;

    /** @var list<string> */
    private const STEPS = [
        'precheck',
        'fixture_content_create',
        'content_plan',
        'content_apply_verify',
        'content_rollback',
        'fixture_navigation_create',
        'navigation_plan',
        'navigation_apply_verify',
        'navigation_rollback',
        'seo_plan',
        'seo_apply_verify',
        'seo_rollback',
        'cleanup',
    ];

    public function __construct(private readonly ?WordPressAdapter $adapter = null) {}

    /** @return list<string> */
    public static function steps(): array
    {
        return self::STEPS;
    }

    public static function pauseMs(): int
    {
        return self::PAUSE_MS;
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    /** @return list<array<string,mixed>> */
    public function history(): array
    {
        $history = get_option(self::HISTORY_OPTION, []);
        if (! is_array($history)) {
            return [];
        }
        return array_values(array_filter($history, 'is_array'));
    }

    /** @return array<string,mixed> */
    public function start(): array
    {
        $current = $this->current();
        if (in_array((string) ($current['status'] ?? ''), ['running','cleaning'], true)) {
            return $current;
        }

        $runId = 'sm-accept-' . substr(hash('sha256', (string) microtime(true) . ':' . wp_rand()), 0, 20);
        $now = gmdate(DATE_W3C);
        $state = [
            'schema_version'=>1,
            'run_id'=>$runId,
            'status'=>'running',
            'profile'=>'low-resource',
            'concurrency'=>1,
            'pause_ms'=>self::PAUSE_MS,
            'step_index'=>0,
            'next_step'=>self::STEPS[0],
            'started_at'=>$now,
            'updated_at'=>$now,
            'finished_at'=>'',
            'had_failure'=>false,
            'last_error'=>'',
            'fixture'=>[
                'post_id'=>0,
                'menu_id'=>0,
                'menu_item_id'=>0,
            ],
            'artifacts'=>[],
            'results'=>[],
        ];
        $this->save($state);
        return $state;
    }

    /** @return array<string,mixed> */
    public function cancel(): array
    {
        $state = $this->current();
        if ([] === $state || in_array((string) ($state['status'] ?? ''), ['completed','failed'], true)) {
            return $state;
        }
        $state['had_failure'] = true;
        $state['last_error'] = 'cancelled_by_user';
        $state['status'] = 'cleaning';
        $state['step_index'] = count(self::STEPS) - 1;
        $state['next_step'] = 'cleanup';
        $state['updated_at'] = gmdate(DATE_W3C);
        $this->save($state);
        return $state;
    }

    /**
     * Execute exactly one bounded phase. Never loops over phases.
     *
     * @return array<string,mixed>
     */
    public function advance(): array
    {
        $state = $this->current();
        if ([] === $state) {
            throw new RuntimeException('acceptance_run_missing');
        }
        if (! in_array((string) ($state['status'] ?? ''), ['running','cleaning'], true)) {
            return $state;
        }

        $runId = (string) ($state['run_id'] ?? '');
        if (! $this->acquireLock($runId)) {
            throw new RuntimeException('acceptance_run_busy');
        }

        try {
            $state = $this->current();
            $index = max(0, (int) ($state['step_index'] ?? 0));
            $step = self::STEPS[$index] ?? 'cleanup';

            try {
                $evidence = $this->executeStep($step, $state);
                $state['results'][$step] = [
                    'status'=>'passed',
                    'at'=>gmdate(DATE_W3C),
                    'evidence'=>$this->boundedEvidence($evidence),
                ];
                $index++;

                if ($index >= count(self::STEPS)) {
                    $state['step_index'] = count(self::STEPS);
                    $state['next_step'] = '';
                    $state['status'] = ! empty($state['had_failure']) ? 'failed' : 'completed';
                    $state['finished_at'] = gmdate(DATE_W3C);
                    $state['updated_at'] = $state['finished_at'];
                    $this->save($state);
                    $this->appendHistory($state);
                    return $state;
                }

                $state['step_index'] = $index;
                $state['next_step'] = self::STEPS[$index];
                $state['updated_at'] = gmdate(DATE_W3C);
                $this->save($state);
                return $state;
            } catch (Throwable) {
                $state['results'][$step] = [
                    'status'=>'failed',
                    'at'=>gmdate(DATE_W3C),
                    'evidence'=>['code'=>'step_failed'],
                ];
                $state['had_failure'] = true;
                $state['last_error'] = $step . '_failed';
                $state['status'] = 'cleaning';
                $state['step_index'] = count(self::STEPS) - 1;
                $state['next_step'] = 'cleanup';
                $state['updated_at'] = gmdate(DATE_W3C);
                $this->save($state);
                return $state;
            }
        } finally {
            $this->releaseLock($runId);
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function executeStep(string $step, array &$state): array
    {
        return match ($step) {
            'precheck' => $this->precheck(),
            'fixture_content_create' => $this->createContentFixture($state),
            'content_plan' => $this->planContent($state),
            'content_apply_verify' => $this->applyVerify('content', $state),
            'content_rollback' => $this->rollback('content', $state),
            'fixture_navigation_create' => $this->createNavigationFixture($state),
            'navigation_plan' => $this->planNavigation($state),
            'navigation_apply_verify' => $this->applyVerify('navigation', $state),
            'navigation_rollback' => $this->rollback('navigation', $state),
            'seo_plan' => $this->planSeo($state),
            'seo_apply_verify' => $this->applyVerify('seo', $state),
            'seo_rollback' => $this->rollback('seo', $state),
            'cleanup' => $this->cleanup($state),
            default => throw new RuntimeException('unknown_acceptance_step'),
        };
    }

    /** @return array<string,mixed> */
    private function precheck(): array
    {
        $adapter = $this->adapter();
        $snapshot = $adapter->discover();
        $graph = $adapter->capabilities($snapshot);
        $required = ['content.write','seo.write'];
        $caps = [];
        foreach ($required as $id) {
            $capability = $graph->get($id);
            $caps[$id] = [
                'detected'=>$capability?->detected ?? false,
                'understood'=>$capability?->understood ?? false,
                'governable'=>$capability?->governable ?? false,
                'provider'=>$capability?->provider ?? '',
            ];
            if (null === $capability || ! $capability->governable) {
                throw new RuntimeException('required_capability_not_governable');
            }
        }
        return [
            'fingerprint'=>$snapshot->fingerprint,
            'capabilities'=>$caps,
            'navigation'=>'fixture-created-later',
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function createContentFixture(array &$state): array
    {
        $existing = (int) ($state['fixture']['post_id'] ?? 0);
        if ($existing > 0 && 'page' === get_post_type($existing)) {
            return ['post_id'=>$existing,'reused'=>true];
        }

        $postId = wp_insert_post([
            'post_type'=>'page',
            'post_status'=>'draft',
            'post_title'=>'Site Manager Acceptance Fixture',
            'post_content'=>'Site Manager temporary acceptance fixture. Safe to delete.',
            'post_excerpt'=>'',
        ], true);
        if (is_wp_error($postId) || (int) $postId < 1) {
            throw new RuntimeException('content_fixture_create_failed');
        }
        $state['fixture']['post_id'] = (int) $postId;
        $this->save($state);
        return ['post_id'=>(int) $postId,'status'=>'draft'];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function planContent(array &$state): array
    {
        $postId = $this->fixturePostId($state);
        $plan = $this->makePlan(
            capabilityId: 'content.write',
            operation: 'content.update',
            payload: ['post_id'=>$postId,'fields'=>['title'=>'Site Manager Acceptance Fixture Updated']],
            reason: 'Site Manager Acceptance Center content self-test',
        );
        $state['artifacts']['content_plan'] = $this->serializePlan($plan);
        $this->save($state);
        return [
            'plan_id'=>$plan->id,
            'target'=>['post_id'=>$postId],
            'reversible'=>$plan->reversible,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function createNavigationFixture(array &$state): array
    {
        $menuId = (int) ($state['fixture']['menu_id'] ?? 0);
        $itemId = (int) ($state['fixture']['menu_item_id'] ?? 0);
        if ($menuId > 0 && $itemId > 0) {
            return ['menu_id'=>$menuId,'menu_item_id'=>$itemId,'reused'=>true];
        }

        $menuId = wp_create_nav_menu('Site Manager Acceptance ' . substr((string) ($state['run_id'] ?? ''), -8));
        if (is_wp_error($menuId) || (int) $menuId < 1) {
            throw new RuntimeException('navigation_fixture_menu_failed');
        }
        $itemId = wp_update_nav_menu_item((int) $menuId, 0, [
            'menu-item-title'=>'Site Manager Acceptance Item',
            'menu-item-url'=>home_url('/'),
            'menu-item-status'=>'publish',
            'menu-item-type'=>'custom',
        ]);
        if (is_wp_error($itemId) || (int) $itemId < 1) {
            wp_delete_nav_menu((int) $menuId);
            throw new RuntimeException('navigation_fixture_item_failed');
        }

        $state['fixture']['menu_id'] = (int) $menuId;
        $state['fixture']['menu_item_id'] = (int) $itemId;
        $this->save($state);
        return [
            'menu_id'=>(int) $menuId,
            'menu_item_id'=>(int) $itemId,
            'assigned_to_theme_location'=>false,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function planNavigation(array &$state): array
    {
        $itemId = (int) ($state['fixture']['menu_item_id'] ?? 0);
        if ($itemId < 1) {
            throw new RuntimeException('navigation_fixture_missing');
        }
        $plan = $this->makePlan(
            capabilityId: 'navigation.write',
            operation: 'navigation.update',
            payload: [
                'provider'=>NativeNavigationRuntime::CLASSIC,
                'target_id'=>$itemId,
                'fields'=>['attr_title'=>'Site Manager acceptance temporary'],
            ],
            reason: 'Site Manager Acceptance Center navigation self-test',
        );
        $state['artifacts']['navigation_plan'] = $this->serializePlan($plan);
        $this->save($state);
        return [
            'plan_id'=>$plan->id,
            'provider'=>NativeNavigationRuntime::CLASSIC,
            'target_id'=>$itemId,
            'reversible'=>$plan->reversible,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function planSeo(array &$state): array
    {
        $postId = $this->fixturePostId($state);
        $plan = $this->makePlan(
            capabilityId: 'seo.write',
            operation: 'seo.update',
            payload: [
                'post_id'=>$postId,
                'fields'=>['description'=>'Site Manager SEO acceptance temporary description'],
            ],
            reason: 'Site Manager Acceptance Center SEO self-test',
        );
        $state['artifacts']['seo_plan'] = $this->serializePlan($plan);
        $this->save($state);
        return [
            'plan_id'=>$plan->id,
            'post_id'=>$postId,
            'adapter'=>$plan->adapter,
            'reversible'=>$plan->reversible,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function applyVerify(string $kind, array &$state): array
    {
        $plan = $this->loadPlan($kind, $state);
        $execution = $this->adapter()->apply($plan, true);
        $verification = $this->adapter()->verify($plan, $execution);
        $state['artifacts'][$kind . '_execution'] = $execution->jsonSerialize();
        $state['artifacts'][$kind . '_verification'] = $verification->jsonSerialize();
        $this->save($state);

        if (! $execution->success || ! $verification->verified) {
            throw new RuntimeException($kind . '_verification_failed');
        }
        return [
            'plan_id'=>$plan->id,
            'execution_status'=>$execution->status,
            'verified'=>$verification->verified,
            'verification_status'=>$verification->status,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function rollback(string $kind, array &$state): array
    {
        $plan = $this->loadPlan($kind, $state);
        $executionRaw = $state['artifacts'][$kind . '_execution'] ?? null;
        $execution = is_array($executionRaw) ? $this->hydrateExecution($executionRaw) : null;
        if (null === $execution) {
            throw new RuntimeException($kind . '_execution_missing');
        }
        $rollback = $this->adapter()->rollback($plan, $execution, true);
        $state['artifacts'][$kind . '_rollback'] = $rollback->jsonSerialize();
        $this->save($state);
        if (! $rollback->success) {
            throw new RuntimeException($kind . '_rollback_failed');
        }
        return [
            'plan_id'=>$plan->id,
            'rollback_status'=>$rollback->status,
            'success'=>$rollback->success,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function cleanup(array &$state): array
    {
        $removed = ['post'=>false,'menu'=>false];
        $postId = (int) ($state['fixture']['post_id'] ?? 0);
        if ($postId > 0) {
            $removed['post'] = false !== wp_delete_post($postId, true);
            if ($removed['post']) {
                $state['fixture']['post_id'] = 0;
            }
        } else {
            $removed['post'] = true;
        }

        $menuId = (int) ($state['fixture']['menu_id'] ?? 0);
        if ($menuId > 0) {
            $deleted = wp_delete_nav_menu($menuId);
            $removed['menu'] = ! is_wp_error($deleted);
            if ($removed['menu']) {
                $state['fixture']['menu_id'] = 0;
                $state['fixture']['menu_item_id'] = 0;
            }
        } else {
            $removed['menu'] = true;
        }
        $this->save($state);

        if (! $removed['post'] || ! $removed['menu']) {
            throw new RuntimeException('acceptance_cleanup_failed');
        }
        return ['removed'=>$removed];
    }

    /** @param array<string,mixed> $payload */
    private function makePlan(string $capabilityId, string $operation, array $payload, string $reason): Plan
    {
        $adapter = $this->adapter();
        $snapshot = $adapter->discover();
        $graph = $adapter->capabilities($snapshot);
        $request = new OperationRequest(
            capabilityId: $capabilityId,
            operation: $operation,
            payload: $payload,
            write: true,
            riskClass: 'editorial-review',
            confirmationClass: 'explicit',
            reason: $reason,
        );
        return $adapter->plan($request, $snapshot, $graph);
    }

    /** @param array<string,mixed> $state */
    private function fixturePostId(array $state): int
    {
        $postId = (int) ($state['fixture']['post_id'] ?? 0);
        if ($postId < 1) {
            throw new RuntimeException('content_fixture_missing');
        }
        return $postId;
    }

    /** @param array<string,mixed> $state */
    private function loadPlan(string $kind, array $state): Plan
    {
        $raw = $state['artifacts'][$kind . '_plan'] ?? null;
        if (! is_array($raw)) {
            throw new RuntimeException($kind . '_plan_missing');
        }
        $plan = $this->hydratePlan($raw);
        if (null === $plan) {
            throw new RuntimeException($kind . '_plan_invalid');
        }
        return $plan;
    }

    /** @return array<string,mixed> */
    private function serializePlan(Plan $plan): array
    {
        return [
            'id'=>$plan->id,
            'adapter'=>$plan->adapter,
            'fingerprint'=>$plan->fingerprint,
            'request'=>$plan->request->jsonSerialize(),
            'changes'=>$plan->changes,
            'snapshot'=>$plan->snapshot,
            'reversible'=>$plan->reversible,
            'created_at'=>$plan->createdAt,
        ];
    }

    /** @param array<string,mixed> $data */
    private function hydratePlan(array $data): ?Plan
    {
        $request = is_array($data['request'] ?? null) ? $data['request'] : [];
        if ('' === (string) ($data['id'] ?? '') || '' === (string) ($request['capability_id'] ?? '')) {
            return null;
        }
        $operationRequest = new OperationRequest(
            capabilityId: (string) ($request['capability_id'] ?? ''),
            operation: (string) ($request['operation'] ?? ''),
            payload: is_array($request['payload'] ?? null) ? $request['payload'] : [],
            write: ! empty($request['write']),
            riskClass: (string) ($request['risk_class'] ?? 'low'),
            confirmationClass: (string) ($request['confirmation_class'] ?? 'none'),
            reason: (string) ($request['reason'] ?? ''),
        );
        return new Plan(
            id: (string) $data['id'],
            adapter: (string) ($data['adapter'] ?? ''),
            fingerprint: (string) ($data['fingerprint'] ?? ''),
            request: $operationRequest,
            changes: is_array($data['changes'] ?? null) ? $data['changes'] : [],
            snapshot: is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [],
            reversible: ! empty($data['reversible']),
            createdAt: (string) ($data['created_at'] ?? ''),
        );
    }

    /** @param array<string,mixed> $data */
    private function hydrateExecution(array $data): ?ExecutionResult
    {
        if (! array_key_exists('success', $data) || '' === (string) ($data['status'] ?? '')) {
            return null;
        }
        return new ExecutionResult(
            success: ! empty($data['success']),
            status: (string) $data['status'],
            data: is_array($data['data'] ?? null) ? $data['data'] : [],
            snapshotId: (string) ($data['snapshot_id'] ?? ''),
        );
    }

    private function adapter(): WordPressAdapter
    {
        return $this->adapter ?? new WordPressAdapter();
    }

    /** @param array<string,mixed> $state */
    private function save(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /** @param array<string,mixed> $state */
    private function appendHistory(array $state): void
    {
        $history = $this->history();
        array_unshift($history, [
            'run_id'=>(string) ($state['run_id'] ?? ''),
            'status'=>(string) ($state['status'] ?? ''),
            'profile'=>(string) ($state['profile'] ?? ''),
            'started_at'=>(string) ($state['started_at'] ?? ''),
            'finished_at'=>(string) ($state['finished_at'] ?? ''),
            'last_error'=>(string) ($state['last_error'] ?? ''),
            'results'=>is_array($state['results'] ?? null) ? $state['results'] : [],
        ]);
        $history = array_slice($history, 0, self::HISTORY_LIMIT);
        update_option(self::HISTORY_OPTION, $history, false);
    }

    private function acquireLock(string $runId): bool
    {
        $now = time();
        $lock = get_option(self::LOCK_OPTION, []);
        if (is_array($lock) && (int) ($lock['acquired_at'] ?? 0) > 0) {
            if ((int) $lock['acquired_at'] + self::LOCK_TTL_SECONDS < $now) {
                delete_option(self::LOCK_OPTION);
            } else {
                return false;
            }
        }
        return add_option(self::LOCK_OPTION, ['run_id'=>$runId,'acquired_at'=>$now], '', false);
    }

    private function releaseLock(string $runId): void
    {
        $lock = get_option(self::LOCK_OPTION, []);
        if (is_array($lock) && $runId === (string) ($lock['run_id'] ?? '')) {
            delete_option(self::LOCK_OPTION);
        }
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function boundedEvidence(array $evidence): array
    {
        $encoded = wp_json_encode($evidence);
        if (! is_string($encoded) || strlen($encoded) <= 12000) {
            return $evidence;
        }
        return ['truncated'=>true,'sha256'=>hash('sha256', $encoded)];
    }
}
