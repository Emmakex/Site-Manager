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
$assert(false === ($connection1['write_principal_bound'] ?? true), 'read-only connection must not bind a write principal');
$assert(! array_key_exists('token_hash', $connection1), 'public connection state must not expose token hash');
$assert(! in_array($token1, $stored1, true), 'plaintext token must never be persisted as an option value');
$assert(hash('sha256', $token1) === ($stored1['token_hash'] ?? ''), 'only token digest must be persisted');
$assert(true === $store->tokenIsValid($token1), 'valid token must be introspectable without a capability scope');
$assert(false === $store->tokenIsValid('wrong-token'), 'invalid token must fail introspection');
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

$issued3 = $store->issue(['site.read','content.write'], 42);
$token3 = (string) $issued3['token'];
$connection3 = (array) $issued3['connection'];
$assert(3 === ($connection3['generation'] ?? 0), 'write-capable rotation must increment generation');
$assert(true === ($connection3['write_principal_bound'] ?? false), 'write scope must expose only a non-secret bound-principal flag');
$assert(42 === $store->writePrincipalUserId(), 'write scopes must bind to the administrator who granted them');
$assert(true === $store->authorize($token3, 'content.write'), 'bound write token must authorize its granted scope');
$store->saveScopes(['site.read'], 42);
$assert(0 === $store->writePrincipalUserId(), 'removing all write scopes must remove write-principal binding');
$assert(false === ($store->publicState()['write_principal_bound'] ?? true), 'public state must show no write principal after write scopes are removed');

$summary = RemoteAccess::summarize($serialized, ['site.read','seo.write']);
$assert(6 === $summary['detected'], 'summary must count detected capabilities');
$assert(5 === $summary['governable'], 'summary must count governable capabilities');
$assert(3 === $summary['authorized'], 'site.read must authorize site.read + content.read and seo.write must authorize seo.write');
$assert(1 === $summary['blocked'], 'non-governable understood navigation must remain blocked');

$store->revoke();
$assert(false === $store->authorize($token3, 'site.read'), 'revoked connection must deny its token');
$assert([] === $store->grantedScopes(), 'revoked connection must expose no granted scopes');

// A DB clone duplicates the connection identity and bearer hash. A specific
// user-authorized fresh enrollment must invalidate both on this installation,
// without touching site content or the original installation.
$cloned = $store->issue(['site.read', 'content.write'], 42);
$clonedToken = (string) $cloned['token'];
$clonedId = (string) $cloned['connection']['connection_id'];
$clonedState = $store->state();
$assert(null === $store->replaceClonedIdentity('smc_00000000000000000000000000000000'), 'stale identity must fail closed');
$assert($clonedState === $store->state(), 'stale identity must not alter connection');
$replaced = $store->replaceClonedIdentity($clonedId);
$assert(is_array($replaced), 'confirmed clone recovery must issue new scoped credentials');
$replacementId = (string) ($replaced['connection']['connection_id'] ?? '');
$replacementToken = (string) ($replaced['token'] ?? '');
$assert($replacementId !== $clonedId && str_starts_with($replacementId, 'smc_'), 'clone recovery must create an independent connection ID');
$assert(1 === ($replaced['connection']['generation'] ?? 0), 'new connection identity must start generation one');
$assert(['site.diagnostics', 'site.read'] === ($replaced['connection']['scopes'] ?? []), 'clone recovery must strip all write scopes');
$assert(false === ($replaced['connection']['write_principal_bound'] ?? true), 'clone recovery must remove old write principal');
$assert(false === $store->tokenIsValid($clonedToken), 'original cloned token must be invalid on this WordPress');
$assert(true === $store->authorize($replacementToken, 'site.read'), 'new token must allow a read-only Site Manager operation');
$assert(false === $store->authorize($replacementToken, 'content.write'), 'new identity must not retain cloned write permission');
$assert(null === $store->replaceClonedIdentity($clonedId), 'stale reset cannot replace a newer connection');
$assert(hash('sha256', $clonedId) === ($store->state()['previous_connection_id_sha256'] ?? ''), 'reset must retain only old identity fingerprint for audit');
$assert(hash('sha256', $clonedToken) === ($clonedState['token_hash'] ?? ''), 'the snapshot of the source identity is unchanged');

$assert('abc' === RemoteConnectionStore::bearerFromHeader('Bearer abc'), 'bearer header parser must return token');
$assert('' === RemoteConnectionStore::bearerFromHeader('Basic abc'), 'non-bearer header must be rejected');

echo "PASS: WordPress SM-4A/4C remote connection authorization contract\n";
