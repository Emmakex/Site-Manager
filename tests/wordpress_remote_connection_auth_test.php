<?php
declare(strict_types=1);

$GLOBALS['site_manager_test_options'] = [];
if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['site_manager_test_options'][$name] ?? $default;
    }
}
if (! function_exists('update_option')) {
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['site_manager_test_options'][$name] = $value;
        return true;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\RemoteAccess;
use SiteManager\Adapters\WordPress\RemoteConnectionStore;
use SiteManager\Adapters\WordPress\ScopePolicy;
use SiteManager\Core\Model\Capability;
use SiteManager\Core\Model\CapabilityGraph;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$graph = new CapabilityGraph([
    new Capability('site.read', 'wordpress-core', true, true, true, ['site.inspect'], ['site.read']),
    new Capability('site.diagnostics', 'wordpress-core', true, true, true, ['site.diagnostics'], ['site.diagnostics']),
    new Capability('content.read', 'wordpress-core', true, true, true, ['content.inventory'], ['site.read']),
    new Capability('content.write', 'wordpress-core', true, true, true, ['content.update'], ['content.write']),
    new Capability('navigation.write', 'wordpress-core', true, true, false, ['navigation.update'], ['navigation.write'], ['not governable']),
    new Capability('seo.write', 'seo-provider', true, true, true, ['seo.update'], ['seo.write']),
]);
$serialized = $graph->jsonSerialize();
$grantable = ScopePolicy::grantable($serialized);
$assert(in_array('site.read', $grantable, true), 'site.read must be grantable');
$assert(in_array('site.diagnostics', $grantable, true), 'site.diagnostics must be grantable');
$assert(in_array('content.write', $grantable, true), 'governable content.write must be grantable');
$assert(in_array('seo.write', $grantable, true), 'governable seo.write must be grantable');
$assert(! in_array('navigation.write', $grantable, true), 'non-governable navigation.write must not be grantable');

$filtered = ScopePolicy::filter(['site.read','navigation.write','seo.write'], $grantable);
$assert(['seo.write','site.read'] === $filtered, 'requested scopes must be intersected with current governable scopes');

$store = new RemoteConnectionStore();
$issued = $store->issue(['site.read','site.diagnostics']);
$token1 = (string) $issued['token'];
$connection1 = (array) $issued['connection'];
$stored1 = (array) get_option(RemoteConnectionStore::OPTION, []);
$assert(str_starts_with((string) ($connection1['connection_id'] ?? ''), 'smc_'), 'connection id must use neutral smc_ prefix');
$assert(1 === ($connection1['generation'] ?? 0), 'first token must be generation 1');
$assert(! array_key_exists('token_hash', $connection1), 'public connection state must not expose token hash');
$assert(! in_array($token1, $stored1, true), 'plaintext token must never be persisted as an option value');
$assert(hash('sha256', $token1) === ($stored1['token_hash'] ?? ''), 'only token digest must be persisted');
$assert(false === $store->authorize('wrong-token', 'site.read'), 'wrong token must be denied');
$assert('' === (string) ($store->publicState()['last_used_at'] ?? ''), 'failed authentication must not update last_used_at');
$assert(false === $store->authorize($token1, 'seo.write'), 'valid token without required scope must be denied');
$assert('' === (string) ($store->publicState()['last_used_at'] ?? ''), 'missing scope must not update last_used_at');
$assert(true === $store->authorize($token1, 'site.read'), 'valid token with granted scope must authorize');
$assert('' !== (string) ($store->publicState()['last_used_at'] ?? ''), 'successful authorization must update last_used_at');
$assert('site.read' === (string) ($store->publicState()['last_used_scope'] ?? ''), 'successful authorization must record the used scope');

$issued2 = $store->issue(['site.read']);
$token2 = (string) $issued2['token'];
$connection2 = (array) $issued2['connection'];
$assert(($connection1['connection_id'] ?? '') === ($connection2['connection_id'] ?? ''), 'rotation must preserve connection id');
$assert(2 === ($connection2['generation'] ?? 0), 'rotation must increment generation');
$assert(false === $store->authorize($token1, 'site.read'), 'rotated token must become invalid immediately');
$assert(true === $store->authorize($token2, 'site.read'), 'new rotated token must authorize');

$summary = RemoteAccess::summarize($serialized, ['site.read','seo.write']);
$assert(6 === $summary['detected'], 'summary must count detected capabilities');
$assert(5 === $summary['governable'], 'summary must count governable capabilities');
$assert(3 === $summary['authorized'], 'site.read must authorize site.read + content.read and seo.write must authorize seo.write');
$assert(1 === $summary['blocked'], 'non-governable understood navigation must remain blocked');

$store->revoke();
$assert(false === $store->authorize($token2, 'site.read'), 'revoked connection must deny its token');
$assert([] === $store->grantedScopes(), 'revoked connection must expose no granted scopes');

$assert('abc' === RemoteConnectionStore::bearerFromHeader('Bearer abc'), 'bearer header parser must return token');
$assert('' === RemoteConnectionStore::bearerFromHeader('Basic abc'), 'non-bearer header must be rejected');

echo "PASS: WordPress SM-4A remote connection authorization contract\n";
