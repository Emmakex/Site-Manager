<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use SiteManager\Core\Model\Plan;
use Throwable;

/**
 * Separate, strictly scoped Theme semantic write lifecycle.
 * Site Manager remains the only WordPress governance plugin.
 */
final class RemoteThemeWriteAccess
{
    public static function boot(): void
    {
        $routes = [
            'write/theme/plan'=>'plan',
            'write/theme/apply'=>'apply',
            'write/theme/rollback'=>'rollback',
        ];
        add_action('rest_api_init', static function () use ($routes): void {
            foreach ($routes as $route=>$operation) {
                register_rest_route('site-manager/v1', '/' . $route, [
                    'methods'=>\WP_REST_Server::CREATABLE,
                    'permission_callback'=>static fn(\WP_REST_Request $request): bool =>
                        RemoteWriteAccess::authorizeWriteRequest($request, 'content.write'),
                    'callback'=>[self::class, $operation],
                ], true);
            }
        }, 35);
    }

    public static function plan(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        if (! is_array($params) || [] !== array_diff(array_keys($params), ['fields','reason'])) {
            return self::error(400, 'invalid_theme_request');
        }
        $fields = $params['fields'] ?? null;
        if (! is_array($fields) || [] === $fields || count($fields) > 5) {
            return self::error(400, 'invalid_theme_fields');
        }

        try {
            $service = new ThemeTextGovernance();
            $reason = sanitize_text_field((string) ($params['reason'] ?? 'Theme editorial improvement'));
            $plan = $service->plan($fields, $reason);
            $connection = (new RemoteConnectionStore())->publicState();
            $audit = (new RemotePlanStore())->save(
                $plan, (string) ($connection['connection_id'] ?? ''),
                (int) ($connection['generation'] ?? 0),
            );
            $before = is_array($plan->snapshot['original_model'] ?? null)
                ? $plan->snapshot['original_model'] : [];
            $beforePreview = [];
            foreach (array_keys($plan->changes['fields']) as $field) {
                $beforePreview[$field] = $before[$field] ?? null;
            }
            // Raw values are for a private, authenticated MCP Preview only.
            // The GitHub Gateway must whitelist evidence and discard values.
            return self::response(200, [
                'plan'=>$audit,
                'changes'=>[
                    'before'=>$beforePreview,
                    'after'=>$plan->changes['fields'],
                    'source'=>'seo-geo-theme-semantic',
                ],
            ]);
        } catch (\InvalidArgumentException) {
            return self::error(400, 'invalid_theme_slot');
        } catch (\LogicException) {
            return self::error(409, 'theme_model_not_governable');
        } catch (Throwable) {
            return self::error(500, 'theme_plan_failed');
        }
    }

    public static function apply(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        if (! is_array($params) || set_keys($params, ['plan_id','confirmed']) === false
            || $params['confirmed'] !== true || ! is_string($params['plan_id'])) {
            return self::error(409, 'confirmation_required');
        }
        $connection = (new RemoteConnectionStore())->publicState();
        $store = new RemotePlanStore();
        $plan = $store->pending(
            (string) $params['plan_id'],
            (string) ($connection['connection_id'] ?? ''),
            (int) ($connection['generation'] ?? 0),
        );
        if (! $plan instanceof Plan || ! self::belongsToTheme($plan)) {
            return self::error(409, 'theme_plan_not_available');
        }

        try {
            if ((new WordPressAdapter())->discover()->fingerprint !== $plan->fingerprint) {
                $store->markRejected($plan, 'stale-site-theme-plan');
                return self::error(409, 'stale_site_theme_plan');
            }
            $service = new ThemeTextGovernance();
            $result = $service->apply($plan);
            $verification = $service->verify($plan, $result);
            $audit = $store->markApplied($plan, $result, $verification);
            return self::response($result->success && $verification->verified ? 200 : 409, [
                'status'=>$result->success && $verification->verified ? 'verified' : 'theme_verification_failed',
                'plan'=>$audit,
                'execution'=>$result->jsonSerialize(),
                'verification'=>$verification->jsonSerialize(),
                'rollback_available'=>$result->success && $plan->reversible,
            ]);
        } catch (Throwable) {
            return self::error(500, 'theme_apply_failed');
        }
    }

    public static function rollback(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        if (! is_array($params) || set_keys($params, ['plan_id','confirmed']) === false
            || $params['confirmed'] !== true || ! is_string($params['plan_id'])) {
            return self::error(409, 'confirmation_required');
        }
        $connection = (new RemoteConnectionStore())->publicState();
        $store = new RemotePlanStore();
        $record = $store->applied(
            (string) $params['plan_id'],
            (string) ($connection['connection_id'] ?? ''),
            (int) ($connection['generation'] ?? 0),
        );
        if (! is_array($record) || ! ($record['plan'] ?? null) instanceof Plan
            || ! self::belongsToTheme($record['plan'])) {
            return self::error(409, 'theme_rollback_not_available');
        }
        try {
            $rollback = (new ThemeTextGovernance())->rollback($record['plan'], $record['execution']);
            $audit = $store->markRolledBack($record['plan'], $rollback);
            return self::response($rollback->success ? 200 : 409, [
                'status'=>$rollback->status,
                'plan'=>$audit,
                'rollback'=>$rollback->jsonSerialize(),
            ]);
        } catch (Throwable) {
            return self::error(500, 'theme_rollback_failed');
        }
    }

    private static function belongsToTheme(Plan $plan): bool
    {
        return $plan->adapter === 'seo-geo-theme-semantic'
            && $plan->request->capabilityId === 'content.write'
            && $plan->request->operation === 'theme.update'
            && $plan->request->confirmationClass === 'explicit';
    }

    /** @param array<string,mixed> $value */
    private static function response(int $status, array $value): \WP_REST_Response
    {
        return new \WP_REST_Response(['data'=>$value], $status);
    }

    private static function error(int $status, string $code): \WP_REST_Response
    {
        return new \WP_REST_Response(['error'=>['code'=>$code]], $status);
    }
}

/** @param array<string,mixed> $data @param list<string> $keys */
function set_keys(array $data, array $keys): bool
{
    return array_keys($data) !== [] && count($data) === count($keys)
        && array_diff(array_keys($data), $keys) === [];
}
