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
if (! function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 0): int
    {
        return 515151;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\RemoteAcceptanceService;
use SiteManager\Adapters\WordPress\RemoteClientTracker;
use SiteManager\Adapters\WordPress\RemoteConnectionStore;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$connectionId = 'smc_1234567890abcdef1234567890abcdef';
$GLOBALS['site_manager_test_options'][RemoteConnectionStore::OPTION] = [
    'schema_version'=>2,
    'connection_id'=>$connectionId,
    'enabled'=>true,
    'generation'=>3,
    'scopes'=>['content.write','navigation.write','seo.write','site.read'],
    'token_hash'=>str_repeat('a', 64),
    'write_principal_user_id'=>1,
];
$GLOBALS['site_manager_test_options'][RemoteClientTracker::OPTION] = [
    'schema_version'=>1,
    'connection_id'=>$connectionId,
    'connection_generation'=>3,
    'kind'=>'mcp',
    'version'=>'0.5.0',
    'last_seen_at'=>gmdate(DATE_W3C),
    'last_route'=>'/site-manager/v1/connection',
];

$service = new RemoteAcceptanceService();
$state = $service->start();
$assert('queued' === ($state['status'] ?? ''), 'MCP acceptance must start queued for the bridge');
$assert('mcp' === ($state['transport'] ?? ''), 'MCP acceptance must persist its transport');
$assert('mcp-low-resource' === ($state['profile'] ?? ''), 'MCP acceptance must use the low-resource profile');
$assert(1 === ($state['concurrency'] ?? 0), 'MCP acceptance concurrency must be one');
$assert(2000 === ($state['pause_ms'] ?? 0), 'MCP acceptance must pause two seconds between active phases');
$assert('precheck' === ($state['next_step'] ?? ''), 'MCP acceptance must start with precheck');
$assert($connectionId === ($state['connection_id'] ?? ''), 'MCP acceptance must bind to the current connection');
$assert(3 === ($state['connection_generation'] ?? 0), 'MCP acceptance must bind to the current connection generation');
$assert(13 === count(RemoteAcceptanceService::steps()), 'MCP acceptance must keep the same thirteen bounded phases');
$assert(['site.read','content.write','navigation.write','seo.write'] === RemoteAcceptanceService::requiredScopes(), 'MCP acceptance must require every tested scoped capability');

$resumed = $service->start();
$assert(($state['run_id'] ?? '') === ($resumed['run_id'] ?? ''), 'starting again must resume instead of creating duplicate MCP runs');

$cancelled = $service->cancel();
$assert('cleaning' === ($cancelled['status'] ?? ''), 'cancel must schedule cleanup');
$assert('cleanup' === ($cancelled['next_step'] ?? ''), 'cancel must make cleanup the next bounded phase');
$assert(true === ($cancelled['had_failure'] ?? false), 'cancelled MCP run must not be reported as success');

$bridgeState = $service->bridgeState();
$assert('cleanup' === ($bridgeState['next_step'] ?? ''), 'bridge state must expose the persisted next phase');
$assert(2000 === ($bridgeState['pause_ms'] ?? 0), 'bridge state must expose the active low-resource pause');


/* A completed previous full acceptance must not block a new content-only run. */
$previous = (array) get_option(RemoteAcceptanceService::STATE_OPTION, []);
$previous['status'] = 'failed';
$GLOBALS['site_manager_test_options'][RemoteAcceptanceService::STATE_OPTION] = $previous;
$connection = (array) get_option(RemoteConnectionStore::OPTION, []);
$connection['scopes'] = ['site.read', 'content.write'];
$GLOBALS['site_manager_test_options'][RemoteConnectionStore::OPTION] = $connection;

$contentOnly = $service->startContentOnly();
$assert('mcp-content-only' === ($contentOnly['profile'] ?? ''), 'content-only profile must have its own identity');
$contentBridge = $service->bridgeState();
$expectedContentSteps = [
    'precheck', 'fixture_content_create', 'content_plan',
    'content_apply_verify', 'content_rollback', 'cleanup',
];
$assert($expectedContentSteps === ($contentBridge['steps'] ?? []), 'content-only acceptance must contain only its six authorized phases');
$assert('precheck' === ($contentBridge['next_step'] ?? ''), 'content-only acceptance must start at read precheck');
$assert(0 === ($contentOnly['fixture']['post_id'] ?? -1), 'content-only must not reuse an existing fixture');
$assert(0 === ($contentOnly['fixture']['menu_id'] ?? -1), 'content-only must not create a menu');
try {
    $service->start();
    $assert(false, 'a full acceptance run must not replace active content-only acceptance');
} catch (\RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'profile_already_active'), 'profile overlap must fail closed');
}
try {
    $service->startContentOnly();
    $assert(false, 'a duplicate content-only start must not attach another command to the same fixture');
} catch (\RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'already_active'), 'duplicate content-only acceptance must fail closed');
}
$precheck = $service->report((string) $contentOnly['run_id'], 'precheck', true, ['validated'=>true]);
$assert('fixture_content_create' === ($precheck['next_step'] ?? ''), 'content-only precheck must advance to disposable draft creation');
$stopped = $service->cancel();
$assert('cleanup' === ($stopped['next_step'] ?? ''), 'content-only cancel must skip directly to cleanup');
$assert(5 === ($stopped['step_index'] ?? -1), 'content-only cleanup uses its own six-step sequence');

echo "PASS: WordPress MCP remote acceptance low-resource state contract\n";
