<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class RemoteClientTracker
{
    public const OPTION = 'site_manager_remote_client';
    private const MCP_FRESH_SECONDS = 600;

    public static function boot(): void
    {
        add_filter('rest_request_before_callbacks', [new self(), 'observe'], 10, 3);
    }

    /**
     * Record only already-authorized Site Manager bearer traffic. Never persist bearer material.
     */
    public function observe(mixed $response, mixed $handler, mixed $request): mixed
    {
        if (! is_object($request) || ! method_exists($request, 'get_route')) {
            return $response;
        }
        $route = (string) $request->get_route();
        if (! str_starts_with($route, '/site-manager/v1/')) {
            return $response;
        }

        $token = RemoteConnectionStore::bearerFromRequest($request);
        if ('' === $token || ! (new RemoteConnectionStore())->tokenIsValid($token)) {
            return $response;
        }

        $userAgent = method_exists($request, 'get_header')
            ? trim((string) $request->get_header('user-agent'))
            : '';
        $client = $this->classify($userAgent);
        $connection = (new RemoteConnectionStore())->publicState();
        $state = [
            'schema_version'=>1,
            'connection_id'=>(string) ($connection['connection_id'] ?? ''),
            'connection_generation'=>(int) ($connection['generation'] ?? 0),
            'kind'=>$client['kind'],
            'version'=>$client['version'],
            'last_seen_at'=>gmdate(DATE_W3C),
            'last_route'=>$this->boundedRoute($route),
        ];
        update_option(self::OPTION, $state, false);
        return $response;
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** @return array<string,mixed> */
    public function publicState(): array
    {
        $connection = (new RemoteConnectionStore())->publicState();
        $tracked = $this->state();
        $enabled = ! empty($connection['enabled']) && '' !== (string) ($connection['connection_id'] ?? '');
        $sameConnection = $enabled
            && (string) ($tracked['connection_id'] ?? '') === (string) ($connection['connection_id'] ?? '')
            && (int) ($tracked['connection_generation'] ?? 0) === (int) ($connection['generation'] ?? 0);
        $kind = $sameConnection ? (string) ($tracked['kind'] ?? '') : '';
        $version = $sameConnection ? (string) ($tracked['version'] ?? '') : '';
        $lastSeen = $sameConnection ? (string) ($tracked['last_seen_at'] ?? '') : '';
        $fresh = $sameConnection && $this->isFresh($lastSeen);
        $mcpConnected = $fresh && 'mcp' === $kind;

        $status = 'not-connected';
        if ($enabled) {
            $status = $mcpConnected ? 'mcp-connected' : 'connection-prepared';
        }

        return [
            'status'=>$status,
            'connection_enabled'=>$enabled,
            'mcp_connected'=>$mcpConnected,
            'client_kind'=>$kind,
            'client_version'=>$version,
            'last_seen_at'=>$lastSeen,
            'last_route'=>$sameConnection ? (string) ($tracked['last_route'] ?? '') : '',
            'fresh_seconds'=>self::MCP_FRESH_SECONDS,
        ];
    }

    /** @return array{kind:string,version:string} */
    private function classify(string $userAgent): array
    {
        if (1 === preg_match('/(?:^|\\s)site-manager-mcp\\/([0-9A-Za-z._+-]+)/', $userAgent, $match)) {
            return ['kind'=>'mcp','version'=>(string) ($match[1] ?? '')];
        }
        return ['kind'=>'remote-client','version'=>''];
    }

    private function isFresh(string $timestamp): bool
    {
        if ('' === $timestamp) {
            return false;
        }
        $epoch = strtotime($timestamp);
        return false !== $epoch && $epoch >= time() - self::MCP_FRESH_SECONDS;
    }

    private function boundedRoute(string $route): string
    {
        $route = preg_replace('/[^A-Za-z0-9_\\/-]/', '', $route) ?? '';
        return substr($route, 0, 160);
    }
}
