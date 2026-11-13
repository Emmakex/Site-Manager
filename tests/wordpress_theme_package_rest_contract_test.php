<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$GLOBALS['sm_package_routes'] = [];
$GLOBALS['sm_package_hooks'] = [];
function add_action(string $name, callable $callback, int $priority = 10): void {
    $GLOBALS['sm_package_hooks'][$name][] = $callback;
}
function register_rest_route(string $namespace, string $route, array $definition, bool $override = false): void {
    $GLOBALS['sm_package_routes'][$namespace . $route] = $definition;
}
function get_option(string $key, mixed $default = false): mixed {
    return $default;
}
class WP_REST_Server {
    public const CREATABLE = 'POST';
}
class WP_REST_Request {
    public function __construct(private readonly array $json = []) {}
    public function get_json_params(): array { return $this->json; }
    public function get_header(string $name): string { return ''; }
}
class WP_REST_Response {
    public function __construct(private readonly array $data, private readonly int $status = 200) {}
    public function get_status(): int { return $this->status; }
    public function get_data(): array { return $this->data; }
}

use SiteManager\Adapters\WordPress\RemoteThemePackageAccess;
$assert = static function (bool $condition, string $message): void {
    if (! $condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
};
RemoteThemePackageAccess::boot();
$assert(count($GLOBALS['sm_package_hooks']['rest_api_init'] ?? []) === 1, 'REST boot installs one handler');
foreach ($GLOBALS['sm_package_hooks']['rest_api_init'] as $callback) { $callback(); }
$assert(count($GLOBALS['sm_package_routes']) === 3, 'only three registered Theme code routes');
foreach (['plan','apply','rollback'] as $stage) {
    $definition = $GLOBALS['sm_package_routes']['site-manager/v1/write/theme/package/' . $stage] ?? null;
    $assert(is_array($definition) && $definition['methods'] === WP_REST_Server::CREATABLE, 'only POST method');
    $assert(is_callable($definition['permission_callback']), 'permission callback bound');
    $assert(($definition['permission_callback'])(new WP_REST_Request()) === false,
        'unsigned requests are rejected even with installed route');
}
$badPlan = RemoteThemePackageAccess::plan(new WP_REST_Request(['package_url'=>'https://untrusted.test/run.zip']));
$assert($badPlan->get_status() === 400, 'untrusted arbitrary package URL rejected');
$assert(($badPlan->get_data()['error']['code'] ?? '') === 'invalid_theme_package_plan', 'static error');
$badPlan2 = RemoteThemePackageAccess::plan(new WP_REST_Request([
    'theme_slug'=>'client-theme',
    'package_sha256'=>str_repeat('a', 64),
    'package_base64'=>'ZmFrZQ==',
    'source_commit_sha'=>str_repeat('a', 40),
    'source_run_id'=>10,
    'source_artifact_id'=>11,
    'shell_command'=>'rm -rf /',
]));
$assert($badPlan2->get_status() === 400, 'unregistered shell key rejected');
$badConfirm = RemoteThemePackageAccess::apply(new WP_REST_Request([
    'plan_id'=>'sm-plan-' . str_repeat('a',24),
    'confirmed'=>false,
]));
$assert($badConfirm->get_status() === 409, 'apply without explicit confirmation rejected');
$badRollback = RemoteThemePackageAccess::rollback(new WP_REST_Request([
    'plan_id'=>'sm-plan-' . str_repeat('a',24),
    'confirmed'=>true,
    'path'=>'/tmp/arbitrary-backup.zip',
]));
$assert($badRollback->get_status() === 409, 'rollback rejects caller-selected filesystem path');
echo "Theme package REST surface: OK (3 scoped POST endpoints, no bearer, forbidden URL/shell/path, mandatory approval)\n";
