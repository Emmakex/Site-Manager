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
if (! function_exists('add_filter')) {
    function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return true;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\RemoteClientTracker;
use SiteManager\Adapters\WordPress\RemoteConnectionStore;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$secret = 'tracker-contract-secret';
$GLOBALS['site_manager_test_options'][RemoteConnectionStore::OPTION] = [
    'schema_version'=>2,
    'connection_id'=>'smc_tracker_contract',
    'enabled'=>true,
    'generation'=>3,
    'scopes'=>['site.read'],
    'token_hash'=>hash('sha256', $secret),
    'write_principal_user_id'=>0,
    'created_at'=>gmdate(DATE_W3C),
    'rotated_at'=>gmdate(DATE_W3C),
    'last_used_at'=>'',
    'last_used_scope'=>'',
];

$tracker = new RemoteClientTracker();
$before = $tracker->publicState();
$assert('connection-prepared' === ($before['status'] ?? ''), 'issued credentials without an observed MCP client must be connection-prepared');
$assert(false === ($before['mcp_connected'] ?? null), 'connection-prepared must not be reported as MCP connected');

$request = new class($secret) {
    public function __construct(private string $secret) {}
    public function get_route(): string { return '/site-manager/v1/connection'; }
    public function get_header(string $name): string
    {
        return match (strtolower($name)) {
            'authorization' => 'Bearer ' . $this->secret,
            'user-agent' => 'site-manager-mcp/0.4.0',
            default => '',
        };
    }
};

$tracker->observe(null, null, $request);
$connected = $tracker->publicState();
$assert('mcp-connected' === ($connected['status'] ?? ''), 'authenticated MCP traffic must promote the state to mcp-connected');
$assert(true === ($connected['mcp_connected'] ?? false), 'authenticated MCP traffic must set mcp_connected');
$assert('mcp' === ($connected['client_kind'] ?? ''), 'client kind must identify MCP without storing bearer material');
$assert('0.4.0' === ($connected['client_version'] ?? ''), 'MCP version must be parsed from the bounded user agent');
$assert('/site-manager/v1/connection' === ($connected['last_route'] ?? ''), 'last Site Manager route may be retained as bounded operational evidence');

$stored = serialize((array) get_option(RemoteClientTracker::OPTION, []));
$assert(! str_contains($stored, $secret), 'tracker state must never persist the plaintext bearer');
$assert(! str_contains($stored, hash('sha256', $secret)), 'tracker state must not duplicate the bearer digest');

$rotated = $GLOBALS['site_manager_test_options'][RemoteConnectionStore::OPTION];
$rotated['generation'] = 4;
$rotated['token_hash'] = hash('sha256', 'new-secret');
$GLOBALS['site_manager_test_options'][RemoteConnectionStore::OPTION] = $rotated;
$afterRotation = $tracker->publicState();
$assert('connection-prepared' === ($afterRotation['status'] ?? ''), 'a connection generation change must invalidate prior MCP presence');
$assert(false === ($afterRotation['mcp_connected'] ?? null), 'prior MCP presence must not survive token rotation');

echo "PASS: WordPress remote MCP client tracker contract\n";
