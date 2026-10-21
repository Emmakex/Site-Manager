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
if (! function_exists('add_option')) {
    function add_option(string $name, mixed $value = '', mixed $deprecated = '', mixed $autoload = 'yes'): bool
    {
        if (array_key_exists($name, $GLOBALS['site_manager_test_options'])) {
            return false;
        }
        $GLOBALS['site_manager_test_options'][$name] = $value;
        return true;
    }
}
if (! function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['site_manager_test_options'][$name]);
        return true;
    }
}
if (! function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 0): int
    {
        return 424242;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\AcceptanceCenterService;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$steps = AcceptanceCenterService::steps();
$assert(13 === count($steps), 'acceptance sequence must have exactly thirteen bounded phases');
$assert('precheck' === $steps[0], 'acceptance sequence must start with precheck');
$assert('cleanup' === $steps[count($steps) - 1], 'acceptance sequence must always end with cleanup');
$assert(2000 === AcceptanceCenterService::pauseMs(), 'low-resource profile must pause two seconds between requests');

$service = new AcceptanceCenterService();
$state = $service->start();
$assert('running' === ($state['status'] ?? ''), 'new acceptance run must start running');
$assert('low-resource' === ($state['profile'] ?? ''), 'default acceptance profile must be low-resource');
$assert(1 === ($state['concurrency'] ?? 0), 'acceptance concurrency must be hard-limited to one');
$assert('precheck' === ($state['next_step'] ?? ''), 'first persisted step must be precheck');
$assert(0 === ($state['step_index'] ?? -1), 'first persisted step index must be zero');
$assert(2000 === ($state['pause_ms'] ?? 0), 'run state must persist the low-resource pause');
$runId = (string) ($state['run_id'] ?? '');
$assert(str_starts_with($runId, 'sm-accept-'), 'run id must use the acceptance prefix');

$resumed = $service->start();
$assert($runId === ($resumed['run_id'] ?? ''), 'starting during an active run must resume instead of creating concurrent work');

$cancelled = $service->cancel();
$assert('cleaning' === ($cancelled['status'] ?? ''), 'cancel must transition to cleanup rather than abandon fixtures');
$assert(true === ($cancelled['had_failure'] ?? false), 'cancelled run must retain non-success state');
$assert('cleanup' === ($cancelled['next_step'] ?? ''), 'cancel must jump directly to cleanup');
$assert('cancelled_by_user' === ($cancelled['last_error'] ?? ''), 'cancel reason must be bounded and explicit');

$stored = $service->current();
$assert($runId === ($stored['run_id'] ?? ''), 'active run state must survive request boundaries');
$assert([] === $service->history(), 'history must be empty before a run reaches a terminal state');

echo "PASS: WordPress Acceptance Center sequential low-resource state contract\n";
