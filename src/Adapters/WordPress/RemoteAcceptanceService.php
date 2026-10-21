<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use RuntimeException;

final class RemoteAcceptanceService
{
    public const STATE_OPTION = 'site_manager_remote_acceptance_run';
    public const PAUSE_MS = 2000;

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

    /** @var list<string> */
    private const REQUIRED_SCOPES = [
        'site.read',
        'content.write',
        'navigation.write',
        'seo.write',
    ];

    /** @return list<string> */
    public static function steps(): array
    {
        return self::STEPS;
    }

    /** @return list<string> */
    public static function requiredScopes(): array
    {
        return self::REQUIRED_SCOPES;
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    /** @return array<string,mixed> */
    public function start(): array
    {
        $client = (new RemoteClientTracker())->publicState();
        if (empty($client['mcp_connected'])) {
            throw new RuntimeException('mcp_not_connected');
        }

        $missing = array_values(array_diff(self::REQUIRED_SCOPES, (new RemoteConnectionStore())->grantedScopes()));
        if ([] !== $missing) {
            throw new RuntimeException('mcp_acceptance_missing_scopes:' . implode(',', $missing));
        }

        $current = $this->current();
        if (in_array((string) ($current['status'] ?? ''), ['queued','running','cleaning'], true)) {
            return $current;
        }

        $connection = (new RemoteConnectionStore())->publicState();
        $now = gmdate(DATE_W3C);
        $state = [
            'schema_version'=>1,
            'run_id'=>'sm-mcp-accept-' . substr(hash('sha256', (string) microtime(true) . ':' . wp_rand()), 0, 20),
            'transport'=>'mcp',
            'status'=>'queued',
            'profile'=>'mcp-low-resource',
            'concurrency'=>1,
            'pause_ms'=>self::PAUSE_MS,
            'step_index'=>0,
            'next_step'=>self::STEPS[0],
            'started_at'=>$now,
            'updated_at'=>$now,
            'finished_at'=>'',
            'had_failure'=>false,
            'last_error'=>'',
            'connection_id'=>(string) ($connection['connection_id'] ?? ''),
            'connection_generation'=>(int) ($connection['generation'] ?? 0),
            'fixture'=>['post_id'=>0,'menu_id'=>0,'menu_item_id'=>0],
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

    /** @return array<string,mixed> */
    public function bridgeState(): array
    {
        $state = $this->current();
        if ([] === $state) {
            return [
                'transport'=>'mcp',
                'status'=>'idle',
                'next_step'=>'',
                'pause_ms'=>self::PAUSE_MS,
                'steps'=>self::STEPS,
            ];
        }
        $state['steps'] = self::STEPS;
        $state['pause_ms'] = self::PAUSE_MS;
        return $state;
    }

    /** @return array<string,mixed> */
    public function createContentFixture(string $runId): array
    {
        $state = $this->requireStep($runId, 'fixture_content_create');
        $postId = wp_insert_post([
            'post_type'=>'page',
            'post_status'=>'draft',
            'post_title'=>'Site Manager MCP Acceptance Fixture',
            'post_content'=>'Site Manager temporary MCP acceptance fixture. Safe to delete.',
            'post_excerpt'=>'',
        ], true);
        if (is_wp_error($postId) || (int) $postId < 1) {
            return $this->failStep($state, 'fixture_content_create', 'content_fixture_create_failed');
        }
        $state['fixture']['post_id'] = (int) $postId;
        return $this->passAndAdvance($state, 'fixture_content_create', ['post_id'=>(int) $postId,'status'=>'draft']);
    }

    /** @return array<string,mixed> */
    public function createNavigationFixture(string $runId): array
    {
        $state = $this->requireStep($runId, 'fixture_navigation_create');
        $menuId = wp_create_nav_menu('Site Manager MCP Acceptance ' . substr($runId, -8));
        if (is_wp_error($menuId) || (int) $menuId < 1) {
            return $this->failStep($state, 'fixture_navigation_create', 'navigation_fixture_menu_failed');
        }
        $itemId = wp_update_nav_menu_item((int) $menuId, 0, [
            'menu-item-title'=>'Site Manager MCP Acceptance Item',
            'menu-item-url'=>home_url('/'),
            'menu-item-status'=>'publish',
            'menu-item-type'=>'custom',
            'menu-item-position'=>1,
        ]);
        if (is_wp_error($itemId) || (int) $itemId < 1) {
            wp_delete_nav_menu((int) $menuId);
            return $this->failStep($state, 'fixture_navigation_create', 'navigation_fixture_item_failed');
        }
        $state['fixture']['menu_id'] = (int) $menuId;
        $state['fixture']['menu_item_id'] = (int) $itemId;
        return $this->passAndAdvance($state, 'fixture_navigation_create', [
            'menu_id'=>(int) $menuId,
            'menu_item_id'=>(int) $itemId,
            'menu_order'=>1,
            'assigned_to_theme_location'=>false,
        ]);
    }

    /**
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function report(string $runId, string $step, bool $success, array $evidence): array
    {
        $state = $this->requireStep($runId, $step);
        if (! $success) {
            return $this->failStep($state, $step, 'mcp_' . $step . '_failed', $evidence);
        }

        $bounded = $this->boundedEvidence($evidence);
        if (str_ends_with($step, '_plan')) {
            $planId = (string) ($bounded['plan_id'] ?? '');
            if ('' === $planId || ! str_starts_with($planId, 'sm-plan-')) {
                return $this->failStep($state, $step, 'mcp_plan_id_missing', $bounded);
            }
            $kind = substr($step, 0, -5);
            $state['artifacts'][$kind . '_plan_id'] = $planId;
        }

        return $this->passAndAdvance($state, $step, $bounded);
    }

    /** @return array<string,mixed> */
    public function cleanup(string $runId): array
    {
        $state = $this->requireStep($runId, 'cleanup');
        $removed = ['post'=>true,'menu'=>true];

        $postId = (int) ($state['fixture']['post_id'] ?? 0);
        if ($postId > 0) {
            $removed['post'] = false !== wp_delete_post($postId, true);
            if ($removed['post']) {
                $state['fixture']['post_id'] = 0;
            }
        }

        $menuId = (int) ($state['fixture']['menu_id'] ?? 0);
        if ($menuId > 0) {
            $deleted = wp_delete_nav_menu($menuId);
            $removed['menu'] = ! is_wp_error($deleted);
            if ($removed['menu']) {
                $state['fixture']['menu_id'] = 0;
                $state['fixture']['menu_item_id'] = 0;
            }
        }

        $state['results']['cleanup'] = [
            'status'=>$removed['post'] && $removed['menu'] ? 'passed' : 'failed',
            'at'=>gmdate(DATE_W3C),
            'evidence'=>['removed'=>$removed],
        ];
        if (! $removed['post'] || ! $removed['menu']) {
            $state['had_failure'] = true;
            $state['last_error'] = 'acceptance_cleanup_failed';
        }
        $state['step_index'] = count(self::STEPS);
        $state['next_step'] = '';
        $state['status'] = ! empty($state['had_failure']) ? 'failed' : 'completed';
        $state['finished_at'] = gmdate(DATE_W3C);
        $state['updated_at'] = $state['finished_at'];
        $this->save($state);
        $this->appendHistory($state);
        return $state;
    }

    /** @return array<string,mixed> */
    private function requireStep(string $runId, string $step): array
    {
        $state = $this->current();
        if ([] === $state || '' === $runId || $runId !== (string) ($state['run_id'] ?? '')) {
            throw new RuntimeException('mcp_acceptance_run_mismatch');
        }
        if (! in_array((string) ($state['status'] ?? ''), ['queued','running','cleaning'], true)) {
            throw new RuntimeException('mcp_acceptance_run_not_active');
        }
        if ($step !== (string) ($state['next_step'] ?? '')) {
            throw new RuntimeException('mcp_acceptance_step_mismatch');
        }
        if ('queued' === (string) ($state['status'] ?? '')) {
            $state['status'] = 'running';
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    private function passAndAdvance(array $state, string $step, array $evidence): array
    {
        $index = (int) ($state['step_index'] ?? 0);
        $state['results'][$step] = [
            'status'=>'passed',
            'at'=>gmdate(DATE_W3C),
            'evidence'=>$this->boundedEvidence($evidence),
        ];
        $index++;
        if ($index >= count(self::STEPS)) {
            throw new RuntimeException('mcp_acceptance_invalid_terminal_step');
        }
        $state['step_index'] = $index;
        $state['next_step'] = self::STEPS[$index];
        $state['status'] = 'cleanup' === $state['next_step'] ? 'cleaning' : 'running';
        $state['updated_at'] = gmdate(DATE_W3C);
        $this->save($state);
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    private function failStep(array $state, string $step, string $code, array $evidence = []): array
    {
        $state['results'][$step] = [
            'status'=>'failed',
            'at'=>gmdate(DATE_W3C),
            'evidence'=>array_merge(['code'=>$code], $this->boundedEvidence($evidence)),
        ];
        $state['had_failure'] = true;
        $state['last_error'] = $code;
        $state['status'] = 'cleaning';
        $state['step_index'] = count(self::STEPS) - 1;
        $state['next_step'] = 'cleanup';
        $state['updated_at'] = gmdate(DATE_W3C);
        $this->save($state);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function save(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /** @param array<string,mixed> $state */
    private function appendHistory(array $state): void
    {
        $history = get_option(AcceptanceCenterService::HISTORY_OPTION, []);
        $history = is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
        array_unshift($history, [
            'run_id'=>(string) ($state['run_id'] ?? ''),
            'status'=>(string) ($state['status'] ?? ''),
            'profile'=>(string) ($state['profile'] ?? 'mcp-low-resource'),
            'transport'=>'mcp',
            'started_at'=>(string) ($state['started_at'] ?? ''),
            'finished_at'=>(string) ($state['finished_at'] ?? ''),
            'last_error'=>(string) ($state['last_error'] ?? ''),
        ]);
        update_option(AcceptanceCenterService::HISTORY_OPTION, array_slice($history, 0, 10), false);
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function boundedEvidence(array $evidence): array
    {
        $bounded = [];
        foreach (array_slice($evidence, 0, 24, true) as $key => $value) {
            $key = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $key) ?? '', 0, 64);
            if ('' === $key) {
                continue;
            }
            if (is_array($value)) {
                $bounded[$key] = $this->boundedEvidence($value);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
                $bounded[$key] = $value;
            } elseif (is_scalar($value)) {
                $bounded[$key] = substr((string) $value, 0, 500);
            }
        }
        return $bounded;
    }
}
