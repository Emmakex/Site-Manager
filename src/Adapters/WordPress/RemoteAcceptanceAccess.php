<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use RuntimeException;
use Throwable;

final class RemoteAcceptanceAccess
{
    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'registerRest'], 35);
    }

    public static function registerRest(): void
    {
        register_rest_route('site-manager/v1', '/acceptance/remote/job', [
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>static fn(\WP_REST_Request $request): bool => self::authorizeMcp($request),
            'callback'=>static fn(): \WP_REST_Response => self::response(200, (new RemoteAcceptanceService())->bridgeState()),
        ], true);

        register_rest_route('site-manager/v1', '/acceptance/remote/fixture/content', [
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>static fn(\WP_REST_Request $request): bool => self::authorizeMcpWrite($request, 'content.write'),
            'callback'=>static function (\WP_REST_Request $request): \WP_REST_Response {
                try {
                    return self::response(200, (new RemoteAcceptanceService())->createContentFixture(self::runId($request)));
                } catch (RuntimeException) {
                    return self::error(409, 'acceptance_state_conflict');
                } catch (Throwable) {
                    return self::error(500, 'acceptance_fixture_failed');
                }
            },
        ], true);

        register_rest_route('site-manager/v1', '/acceptance/remote/fixture/navigation', [
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>static fn(\WP_REST_Request $request): bool => self::authorizeMcpWrite($request, 'navigation.write'),
            'callback'=>static function (\WP_REST_Request $request): \WP_REST_Response {
                try {
                    return self::response(200, (new RemoteAcceptanceService())->createNavigationFixture(self::runId($request)));
                } catch (RuntimeException) {
                    return self::error(409, 'acceptance_state_conflict');
                } catch (Throwable) {
                    return self::error(500, 'acceptance_fixture_failed');
                }
            },
        ], true);

        register_rest_route('site-manager/v1', '/acceptance/remote/report', [
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>static fn(\WP_REST_Request $request): bool => self::authorizeMcp($request),
            'callback'=>static function (\WP_REST_Request $request): \WP_REST_Response {
                try {
                    $params = self::params($request);
                    $evidence = is_array($params['evidence'] ?? null) ? $params['evidence'] : [];
                    return self::response(200, (new RemoteAcceptanceService())->report(
                        self::runId($request),
                        sanitize_key((string) ($params['step'] ?? '')),
                        ! empty($params['success']),
                        $evidence,
                    ));
                } catch (RuntimeException) {
                    return self::error(409, 'acceptance_state_conflict');
                } catch (Throwable) {
                    return self::error(500, 'acceptance_report_failed');
                }
            },
        ], true);

        register_rest_route('site-manager/v1', '/acceptance/remote/cleanup', [
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>static fn(\WP_REST_Request $request): bool => self::authorizeMcpWrite($request, 'content.write'),
            'callback'=>static function (\WP_REST_Request $request): \WP_REST_Response {
                try {
                    return self::response(200, (new RemoteAcceptanceService())->cleanup(self::runId($request)));
                } catch (RuntimeException) {
                    return self::error(409, 'acceptance_state_conflict');
                } catch (Throwable) {
                    return self::error(500, 'acceptance_cleanup_failed');
                }
            },
        ], true);
    }

    private static function authorizeMcp(\WP_REST_Request $request): bool
    {
        if (! self::isMcpRequest($request)) {
            return false;
        }
        $token = RemoteConnectionStore::bearerFromRequest($request);
        return (new RemoteConnectionStore())->tokenIsValid($token);
    }

    private static function authorizeMcpWrite(\WP_REST_Request $request, string $scope): bool
    {
        return self::isMcpRequest($request) && RemoteWriteAccess::authorizeWriteRequest($request, $scope);
    }

    private static function isMcpRequest(\WP_REST_Request $request): bool
    {
        $userAgent = trim((string) $request->get_header('user-agent'));
        return 1 === preg_match('/(?:^|\s)site-manager-mcp\/[0-9A-Za-z._+-]+/', $userAgent);
    }

    private static function runId(\WP_REST_Request $request): string
    {
        $params = self::params($request);
        return substr(sanitize_text_field((string) ($params['run_id'] ?? '')), 0, 80);
    }

    /** @return array<string,mixed> */
    private static function params(\WP_REST_Request $request): array
    {
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
        return self::response($status, ['error'=>['code'=>$code]]);
    }
}
