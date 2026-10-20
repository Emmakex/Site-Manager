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

use SiteManager\Adapters\WordPress\RemotePlanStore;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$request = new OperationRequest(
    capabilityId: 'content.write',
    operation: 'content.update',
    payload: ['post_id'=>77,'fields'=>['title'=>'New title']],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-4C contract test',
);
$plan = new Plan(
    id: 'plan_contract_1',
    adapter: 'wordpress-core',
    fingerprint: 'site-fingerprint-1',
    request: $request,
    changes: [
        'target'=>['post_id'=>77,'post_type'=>'page'],
        'fields'=>['post_title'=>'New title'],
        'precondition_fingerprint'=>'target-before',
    ],
    snapshot: [
        'target'=>['ID'=>77,'post_type'=>'page','post_title'=>'Old title'],
        'target_fingerprint'=>'target-before',
    ],
    reversible: true,
    createdAt: gmdate(DATE_W3C),
);

$store = new RemotePlanStore();
$summary = $store->save($plan, 'smc_contract', 4);
$assert('plan_contract_1' === ($summary['plan_id'] ?? ''), 'plan id must be persisted');
$assert('planned' === ($summary['status'] ?? ''), 'new plan must be pending');
$assert(true === ($summary['confirmation_required'] ?? false), 'remote plan must preserve explicit confirmation');
$assert(true === ($summary['reversible'] ?? false), 'remote plan must preserve rollback readiness');
$assert(['post_title'] === ($summary['fields'] ?? []), 'audit summary must expose changed field names only');
$assert(null !== $store->pending('plan_contract_1', 'smc_contract', 4), 'matching connection generation must hydrate pending plan');
$assert(null === $store->pending('plan_contract_1', 'smc_contract', 5), 'rotated connection generation must invalidate pending plan');
$assert(null === $store->pending('plan_contract_1', 'smc_other', 4), 'different connection id must not load plan');

$execution = new ExecutionResult(true, 'applied', [
    'plan_id'=>'plan_contract_1',
    'post_id'=>77,
    'after_fingerprint'=>'target-after',
], 'plan_contract_1');
$verification = new VerificationResult(true, 'verified', ['post_id'=>77,'fingerprint'=>'target-after']);
$applied = $store->markApplied($plan, $execution, $verification);
$assert('applied' === ($applied['status'] ?? ''), 'successful execution must move remote plan to applied');
$assert(true === (bool) (($applied['verification']['verified'] ?? false)), 'verification evidence must be persisted');
$loaded = $store->applied('plan_contract_1', 'smc_contract', 4);
$assert(null !== $loaded, 'applied plan must remain available for rollback after apply window');
$assert('applied' === $loaded['execution']->status, 'execution result must hydrate for rollback');

$rollback = new RollbackResult(true, 'rolled-back', ['post_id'=>77,'restored_fields'=>['post_title']]);
$rolled = $store->markRolledBack($plan, $rollback);
$assert('rolled-back' === ($rolled['status'] ?? ''), 'successful rollback must become terminal audit state');
$assert(true === (bool) (($rolled['rollback']['success'] ?? false)), 'rollback evidence must be retained');
$assert(null === $store->applied('plan_contract_1', 'smc_contract', 4), 'rolled-back plan must not be reusable as applied rollback target');

$raw = (array) get_option(RemotePlanStore::OPTION, []);
$encoded = serialize($raw);
$assert(! str_contains($encoded, 'OperationRequest"'), 'remote plan persistence must use normalized arrays rather than model objects');
$assert(! str_contains($encoded, 'Plan"'), 'remote plan persistence must not serialize Plan objects');

echo "PASS: WordPress SM-4C remote plan persistence contract\n";
