<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class ScopePolicy
{
    /**
     * Return only scopes backed by currently governable capabilities.
     *
     * @param array<string,mixed>|list<array<string,mixed>> $capabilities
     * @return list<string>
     */
    public static function grantable(array $capabilities): array
    {
        $items = isset($capabilities['capabilities']) && is_array($capabilities['capabilities'])
            ? $capabilities['capabilities']
            : $capabilities;

        $scopes = [];
        foreach ($items as $capability) {
            if (! is_array($capability) || empty($capability['governable'])) {
                continue;
            }
            foreach ((array) ($capability['required_scopes'] ?? []) as $scope) {
                $scope = (string) $scope;
                if ('' !== $scope) {
                    $scopes[$scope] = true;
                }
            }
        }

        $result = array_keys($scopes);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param list<string> $requested
     * @param list<string> $grantable
     * @return list<string>
     */
    public static function filter(array $requested, array $grantable): array
    {
        $allowed = array_fill_keys($grantable, true);
        $result = [];
        foreach ($requested as $scope) {
            $scope = trim((string) $scope);
            if ('' !== $scope && isset($allowed[$scope])) {
                $result[$scope] = true;
            }
        }
        $scopes = array_keys($result);
        sort($scopes, SORT_STRING);
        return $scopes;
    }
}
