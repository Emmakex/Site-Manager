<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use InvalidArgumentException;
use LogicException;
use SiteManager\Core\Model\OperationRequest;
use Throwable;

final class RemoteWriteAccess
{
    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'registerRest'], 30);
    }

    public static function registerRest(): void
    {
        foreach ([
            'write/content/plan'=>'planContent',
            'write/content/apply'=>'applyContent',
            'write/content/rollback'=>'rollbackContent',
        ] as $route => $callback) {
            register_rest_route('site-manager/v1', '/' . $route, [
                'methods'=>\WP_REST_Server::CREATABLE,
                'permission_callback'=>static fn(\WP_REST_Request $request): bool => self::authorizeWriteRequest($request, 'content.write'),
                'callback'=>[self::class, $callback],
            ], true);
        }
    }

    public static function authorizeWriteRequest(object $request, string $scope): bool
    {
        $store = new RemoteConnectionStore();
        $token = RemoteConnectionStore::bearerFromRequest($request);
        if (! $store->authorize($token, $scope)) {
            return false;
        }
        $userId = $store->writePrincipalUserId();
        if ($userId < 1 || ! function_exists('user_can') || ! user_can($userId, 'manage_options')) {
            return false;
        }
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user($userId);
        }
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    public static function planContent(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $params = self::params($request);
            $postId = (int) ($params['post_id'] ?? 0);
            $fields = is_array($params['fields'] ?? null) ? $params['fields'] : [];
            $reason = sanitize_text_field((string) ($params['reason'] ?? 'Remote Site Manager content update'));

            $adapter = new WordPressAdapter();
            $snapshot = $adapter->discover();
            $graph = $adapter->capabilities($snapshot);
            $operation = new OperationRequest(
                capabilityId: 'content.write',
                operation: 'content.update',
                payload: ['post_id'=>$postId,'fields'=>$fields],
                write: true,
                riskClass: 'editorial-review',
                confirmationClass: 'explicit',
                reason: $reason,
            );
            $plan = $adapter->plan($operation, $snapshot, $graph);
            $connection = (new RemoteConnectionStore())->publicState();
            $audit = (new RemotePlanStore())->save(
                $plan,
                (string) ($connection['connection_id'] ?? ''),
                (int) ($connection['generation'] ?? 0),
            );

            $before = is_array($plan->snapshot['target'] ?? null) ? $plan->snapshot['target'] : [];
            $after = is_array($plan->changes['fields'] ?? null) ? $plan->changes['fields'] : [];
            $changedBefore = [];
            foreach (array_keys($after) as $field) {
                $changedBefore[$field] = $before[$field] ?? null;
            }

            return self::response(200, [
                'plan'=>$audit,
                'changes'=>[
                    'before'=>$changedBefore,
                    'after'=>$after,
                ],
            ]);
        } catch (InvalidArgumentException) {
            return self::error(400, 'invalid_request');
        } catch (LogicException) {
            return self::error(409, 'operation_not_governable');
        } catch (Throwable) {
            return self::error(500, 'plan_failed');
        }
    }

    public static function applyContent(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = self::params($request);
        if (true !== ($params['confirmed'] ?? false)) {
            return self::error(409, 'confirmation_required');
        }
        $planId = sanitize_text_field((string) ($params['plan_id'] ?? ''));
        $connection = (new RemoteConnectionStore())->publicState();
        $connectionId = (string) ($connection['connection_id'] ?? '');
        $generation = (int) ($connection['generation'] ?? 0);
        $plans = new RemotePlanStore();
        $plan = $plans->pending($planId, $connectionId, $generation);
        if (null === $plan) {
            return self::error(409, 'plan_not_available');
        }

        try {
            $adapter = new WordPressAdapter();
            $current = $adapter->discover();
            if ($current->fingerprint !== $plan->fingerprint) {
                $plans->markRejected($plan, 'stale-site-plan', [
                    'expected_fingerprint'=>$plan->fingerprint,
                    'actual_fingerprint'=>$current->fingerprint,
                ]);
                return self::error(409, 'stale_site_plan');
            }

            $execution = $adapter->apply($plan, true);
            $verification = $adapter->verify($plan, $execution);
            $audit = $plans->markApplied($plan, $execution, $verification);
            if (! $execution->success) {
                return self::response(409, [
                    'status'=>$execution->status,
                    'plan'=>$audit,
                    'verification'=>$verification->jsonSerialize(),
                ]);
            }
            if (! $verification->verified) {
                return self::response(409, [
                    'status'=>'verification_failed',
                    'plan'=>$audit,
                    'execution'=>$execution->jsonSerialize(),
                    'verification'=>$verification->jsonSerialize(),
                    'rollback_available'=>$plan->reversible,
                ]);
            }

            return self::response(200, [
                'status'=>'verified',
                'plan'=>$audit,
                'execution'=>$execution->jsonSerialize(),
                'verification'=>$verification->jsonSerialize(),
                'rollback_available'=>$plan->reversible,
            ]);
        } catch (Throwable) {
            return self::error(500, 'apply_failed');
        }
    }

    public static function rollbackContent(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = self::params($request);
        if (true !== ($params['confirmed'] ?? false)) {
            return self::error(409, 'confirmation_required');
        }
        $planId = sanitize_text_field((string) ($params['plan_id'] ?? ''));
        $connection = (new RemoteConnectionStore())->publicState();
        $connectionId = (string) ($connection['connection_id'] ?? '');
        $generation = (int) ($connection['generation'] ?? 0);
        $plans = new RemotePlanStore();
        $stored = $plans->applied($planId, $connectionId, $generation);
        if (null === $stored) {
            return self::error(409, 'rollback_not_available');
        }

        try {
            $plan = $stored['plan'];
            $execution = $stored['execution'];
            $rollback = (new WordPressAdapter())->rollback($plan, $execution, true);
            $audit = $plans->markRolledBack($plan, $rollback);
            return self::response($rollback->success ? 200 : 409, [
                'status'=>$rollback->status,
                'plan'=>$audit,
                'rollback'=>$rollback->jsonSerialize(),
            ]);
        } catch (Throwable) {
            return self::error(500, 'rollback_failed');
        }
    }

    /** @return array<string,mixed> */
    private static function params(object $request): array
    {
        if (! method_exists($request, 'get_json_params')) {
            return [];
        }
        $params = $request->get_json_params();
        return is_array($params) ? $params : [];
    }

    /** @param array<string,mixed> $data */
    private static function response(int $status, array $data): \WP_REST_Response
    {
        return new \WP_REST_Response(['data'=>$data], $status);
    }

    private static function error(int $status, string $code): \WP_REST_Response
    {
        return new \WP_REST_Response(['error'=>['code'=>$code]], $status);
    }
}
