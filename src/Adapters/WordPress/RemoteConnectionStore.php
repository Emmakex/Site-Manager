<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class RemoteConnectionStore
{
    public const OPTION = 'site_manager_remote_connection';

    /** @return array<string,mixed> */
    public function state(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** @return array<string,mixed> */
    public function publicState(?array $state = null): array
    {
        $state ??= $this->state();
        return [
            'connection_id'=>(string) ($state['connection_id'] ?? ''),
            'enabled'=>! empty($state['enabled']),
            'generation'=>(int) ($state['generation'] ?? 0),
            'scopes'=>$this->normalizeScopes((array) ($state['scopes'] ?? [])),
            'created_at'=>(string) ($state['created_at'] ?? ''),
            'rotated_at'=>(string) ($state['rotated_at'] ?? ''),
            'last_used_at'=>(string) ($state['last_used_at'] ?? ''),
            'last_used_scope'=>(string) ($state['last_used_scope'] ?? ''),
        ];
    }

    /**
     * Generate/rotate credentials. Plaintext token is returned once and never persisted.
     *
     * @param list<string> $scopes
     * @return array{token:string,connection:array<string,mixed>}
     */
    public function issue(array $scopes): array
    {
        $previous = $this->state();
        $now = gmdate(DATE_W3C);
        $token = bin2hex(random_bytes(32));
        $connectionId = (string) ($previous['connection_id'] ?? '');
        if ('' === $connectionId) {
            $connectionId = 'smc_' . bin2hex(random_bytes(16));
        }

        $state = [
            'schema_version'=>1,
            'connection_id'=>$connectionId,
            'enabled'=>true,
            'generation'=>(int) ($previous['generation'] ?? 0) + 1,
            'scopes'=>$this->normalizeScopes($scopes),
            'token_hash'=>hash('sha256', $token),
            'created_at'=>(string) ($previous['created_at'] ?? $now),
            'rotated_at'=>$now,
            'last_used_at'=>'',
            'last_used_scope'=>'',
        ];
        update_option(self::OPTION, $state, false);

        return ['token'=>$token, 'connection'=>$this->publicState($state)];
    }

    /** @param list<string> $scopes */
    public function saveScopes(array $scopes): array
    {
        $state = $this->state();
        if ('' === (string) ($state['connection_id'] ?? '')) {
            return $this->publicState($state);
        }
        $state['scopes'] = $this->normalizeScopes($scopes);
        update_option(self::OPTION, $state, false);
        return $this->publicState($state);
    }

    public function revoke(): array
    {
        $state = $this->state();
        if ([] === $state) {
            return $this->publicState([]);
        }
        $state['enabled'] = false;
        $state['token_hash'] = '';
        $state['last_used_at'] = '';
        $state['last_used_scope'] = '';
        update_option(self::OPTION, $state, false);
        return $this->publicState($state);
    }

    /** @return list<string> */
    public function grantedScopes(): array
    {
        $state = $this->state();
        if (empty($state['enabled']) || '' === (string) ($state['token_hash'] ?? '')) {
            return [];
        }
        return $this->normalizeScopes((array) ($state['scopes'] ?? []));
    }

    public function authorize(string $token, string $requiredScope): bool
    {
        $state = $this->state();
        if (empty($state['enabled']) || '' === $token || '' === $requiredScope) {
            return false;
        }
        if (! in_array($requiredScope, (array) ($state['scopes'] ?? []), true)) {
            return false;
        }
        $storedHash = (string) ($state['token_hash'] ?? '');
        if (64 !== strlen($storedHash) || ! hash_equals($storedHash, hash('sha256', $token))) {
            return false;
        }

        $state['last_used_at'] = gmdate(DATE_W3C);
        $state['last_used_scope'] = $requiredScope;
        update_option(self::OPTION, $state, false);
        return true;
    }

    public static function bearerFromHeader(string $authorization): string
    {
        if (1 !== preg_match('/^Bearer\s+([^\s]+)$/i', trim($authorization), $match)) {
            return '';
        }
        return (string) ($match[1] ?? '');
    }

    public static function bearerFromRequest(object $request): string
    {
        $header = '';
        if (method_exists($request, 'get_header')) {
            $header = (string) $request->get_header('authorization');
        }
        if ('' === $header) {
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        return self::bearerFromHeader($header);
    }

    /** @param list<mixed> $scopes @return list<string> */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ('' !== $scope && 1 === preg_match('/^[a-z0-9._-]+$/', $scope)) {
                $normalized[$scope] = true;
            }
        }
        $result = array_keys($normalized);
        sort($result, SORT_STRING);
        return $result;
    }
}
