<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use SiteManager\Core\Model\Plan;
use Throwable;

/** Fixed, scoped first-adoption lifecycle; never writes raw Gutenberg body. */
final class RemoteThemeBootstrapAccess
{
    public static function boot(): void
    {
        add_action('rest_api_init', static function (): void {
            foreach (['plan','apply','rollback'] as $stage) {
                register_rest_route('site-manager/v1', '/write/theme/bootstrap/' . $stage, [
                    'methods'=>\WP_REST_Server::CREATABLE,
                    'permission_callback'=>static fn(\WP_REST_Request $request): bool =>
                        RemoteWriteAccess::authorizeWriteRequest($request, 'content.write'),
                    'callback'=>[self::class, $stage],
                ], true);
            }
        }, 35);
    }

    public static function plan(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        if (! is_array($params) || array_diff(array_keys($params), ['fields','reason']) !== []
            || ! is_array($params['fields'] ?? null) || ($params['fields'] ?? []) === []) {
            return self::error(400, 'invalid_theme_bootstrap_request');
        }
        try {
            $service = new ThemeModelBootstrap();
            $reason = sanitize_text_field((string) ($params['reason'] ?? 'First-time corporate homepage model adoption'));
            $plan = $service->plan($params['fields'], $reason);
            $connection = (new RemoteConnectionStore())->publicState();
            $summary = (new RemotePlanStore())->save(
                $plan, (string) ($connection['connection_id'] ?? ''),
                (int) ($connection['generation'] ?? 0)
            );
            // No candidate copy, Gutenberg blocks, URLs or private metadata in this response.
            // Value-level approval is an explicit prerequisite for any Apply command.
            return self::response(200, [
                'plan'=>$summary,
                'preview'=>[
                    'source'=>'seo-geo-theme-semantic-bootstrap',
                    'target_post_id'=>$plan->changes['target']['post_id'],
                    'model_id'=>'corporate-home-v1',
                    'slot_ids'=>$plan->changes['fields'],
                    'slot_count'=>count($plan->changes['fields']),
                    'post_content_unchanged'=>true,
                    'proof_claims_included'=>false,
                    'requires_editorial_confirmation'=>true,
                    'values_included'=>false,
                ],
            ]);
        } catch (\InvalidArgumentException) {
            return self::error(400, 'invalid_theme_bootstrap_fields');
        } catch (\LogicException) {
            return self::error(409, 'theme_bootstrap_not_governable');
        } catch (Throwable) {
            return self::error(500, 'theme_bootstrap_plan_failed');
        }
    }

    public static function apply(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        if (! self::confirmed($params)) {
            return self::error(409, 'confirmation_required');
        }
        $connection = (new RemoteConnectionStore())->publicState();
        $store = new RemotePlanStore();
        $plan = $store->pending(
            $params['plan_id'], (string) ($connection['connection_id'] ?? ''),
            (int) ($connection['generation'] ?? 0)
        );
        if (! $plan instanceof Plan || ! self::belongs($plan)) {
            return self::error(409, 'theme_bootstrap_plan_unavailable');
        }
        try {
            if ((new WordPressAdapter())->discover()->fingerprint !== $plan->fingerprint) {
                $store->markRejected($plan, 'stale-site-bootstrap-plan');
                return self::error(409, 'stale_site_bootstrap_plan');
            }
            $service = new ThemeModelBootstrap();
            $result = $service->apply($plan);
            $verified = $service->verify($plan, $result);
            $summary = $store->markApplied($plan, $result, $verified);
            $success = $result->success && $verified->verified;
            return self::response($success ? 200 : 409, [
                'status'=>$success ? 'verified' : 'theme_bootstrap_verification_failed',
                'plan'=>$summary,
                'execution'=>$result->jsonSerialize(),
                'verification'=>$verified->jsonSerialize(),
                'rollback_available'=>$success && $plan->reversible,
            ]);
        } catch (Throwable) {
            return self::error(500, 'theme_bootstrap_apply_failed');
        }
    }

    public static function rollback(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        if (! self::confirmed($params)) {
            return self::error(409, 'confirmation_required');
        }
        $connection = (new RemoteConnectionStore())->publicState();
        $store = new RemotePlanStore();
        $record = $store->applied(
            $params['plan_id'], (string) ($connection['connection_id'] ?? ''),
            (int) ($connection['generation'] ?? 0)
        );
        if (! is_array($record) || ! ($record['plan'] ?? null) instanceof Plan
            || ! self::belongs($record['plan'])) {
            return self::error(409, 'theme_bootstrap_rollback_unavailable');
        }
        try {
            $rollback = (new ThemeModelBootstrap())->rollback($record['plan'], $record['execution']);
            $summary = $store->markRolledBack($record['plan'], $rollback);
            return self::response($rollback->success ? 200 : 409, [
                'status'=>$rollback->status,
                'plan'=>$summary,
                'rollback'=>$rollback->jsonSerialize(),
            ]);
        } catch (Throwable) {
            return self::error(500, 'theme_bootstrap_rollback_failed');
        }
    }

    private static function confirmed(mixed $params): bool
    {
        return is_array($params) && count($params) === 2
            && array_key_exists('plan_id', $params) && is_string($params['plan_id'])
            && $params['plan_id'] !== ''
            && ($params['confirmed'] ?? null) === true;
    }

    private static function belongs(Plan $plan): bool
    {
        return $plan->adapter === 'seo-geo-theme-semantic-bootstrap'
            && $plan->request->capabilityId === 'content.write'
            && $plan->request->operation === 'theme.bootstrap'
            && $plan->request->confirmationClass === 'explicit';
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
