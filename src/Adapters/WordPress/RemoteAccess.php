<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class RemoteAccess
{
    private const SNAPSHOT_OPTION = 'site_manager_discovery';

    public static function boot(): void
    {
        add_action('admin_menu', [ConnectionPage::class, 'registerMenu'], 20);
        add_action('admin_init', [self::class, 'syncAuthorizationSummary'], 20);
        add_action('rest_api_init', [self::class, 'registerRest'], 20);
    }

    public static function registerRest(): void
    {
        $scopes = [
            'discovery'=>'site.read',
            'capabilities'=>'site.read',
            'diagnostics'=>'site.diagnostics',
        ];

        foreach ($scopes as $resource => $requiredScope) {
            register_rest_route('site-manager/v1', '/' . $resource, [
                'methods'=>\WP_REST_Server::READABLE,
                'permission_callback'=>static function (\WP_REST_Request $request) use ($requiredScope): bool {
                    if (current_user_can('manage_options')) {
                        return true;
                    }
                    $token = RemoteConnectionStore::bearerFromRequest($request);
                    return (new RemoteConnectionStore())->authorize($token, $requiredScope);
                },
                'callback'=>static function () use ($resource): \WP_REST_Response {
                    $state = (new Plugin())->ensureCurrent();
                    $data = 'discovery' === $resource ? ($state['snapshot'] ?? []) : ($state[$resource] ?? []);
                    return new \WP_REST_Response(['data'=>$data], 200);
                },
            ], true);
        }

        register_rest_route('site-manager/v1', '/connection', [
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>static function (\WP_REST_Request $request): bool {
                if (current_user_can('manage_options')) {
                    return true;
                }
                $token = RemoteConnectionStore::bearerFromRequest($request);
                return (new RemoteConnectionStore())->tokenIsValid($token);
            },
            'callback'=>static function (): \WP_REST_Response {
                return new \WP_REST_Response(['data'=>(new RemoteConnectionStore())->publicState()], 200);
            },
        ], true);
    }

    public static function syncAuthorizationSummary(): void
    {
        $state = get_option(self::SNAPSHOT_OPTION, []);
        if (! is_array($state)) {
            return;
        }
        $capabilities = is_array($state['capabilities'] ?? null) ? $state['capabilities'] : [];
        $scopes = (new RemoteConnectionStore())->grantedScopes();
        $summary = self::summarize($capabilities, $scopes);
        if (($state['summary'] ?? null) === $summary) {
            return;
        }
        $state['summary'] = $summary;
        update_option(self::SNAPSHOT_OPTION, $state, false);
    }

    /**
     * @param array<string,mixed>|list<array<string,mixed>> $capabilities
     * @param list<string> $grantedScopes
     * @return array{detected:int,understood:int,governable:int,authorized:int,blocked:int}
     */
    public static function summarize(array $capabilities, array $grantedScopes): array
    {
        $items = isset($capabilities['capabilities']) && is_array($capabilities['capabilities'])
            ? $capabilities['capabilities']
            : $capabilities;
        $summary = ['detected'=>0,'understood'=>0,'governable'=>0,'authorized'=>0,'blocked'=>0];
        foreach ($items as $capability) {
            if (! is_array($capability)) {
                continue;
            }
            $detected = ! empty($capability['detected']);
            $understood = ! empty($capability['understood']);
            $governable = ! empty($capability['governable']);
            if ($detected) { $summary['detected']++; }
            if ($understood) { $summary['understood']++; }
            if ($governable) { $summary['governable']++; }
            if ($detected && $understood && ! $governable) { $summary['blocked']++; }
            if ($governable) {
                $required = array_map('strval', (array) ($capability['required_scopes'] ?? []));
                if ([] === array_diff($required, $grantedScopes)) {
                    $summary['authorized']++;
                }
            }
        }
        return $summary;
    }
}
