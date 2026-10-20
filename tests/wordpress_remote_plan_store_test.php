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

$navigationRequest = new OperationRequest(
    capabilityId: 'navigation.write',
    operation: 'navigation.update',
    payload: [
        'provider'=>'wordpress-classic-menu',
        'target_id'=>88,
        'fields'=>['title'=>'Updated navigation'],
    ],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-4C navigation contract test',
);
$navigationPlan = new Plan(
    id: 'plan_navigation_1',
    adapter: 'wordpress-core',
    fingerprint: 'site-fingerprint-navigation',
    request: $navigationRequest,
    changes: [
        'target'=>['provider'=>'wordpress-classic-menu','target_id'=>88],
        'fields'=>['title'=>'Updated navigation'],
        'precondition_fingerprint'=>'navigation-before',
    ],
    snapshot: [
        'target'=>['provider'=>'wordpress-classic-menu','ID'=>88,'title'=>'Original navigation'],
        'target_fingerprint'=>'navigation-before',
    ],
    reversible: true,
    createdAt: gmdate(DATE_W3C),
);
$navigationSummary = $store->save($navigationPlan, 'smc_contract', 4);
$assert('navigation.write' === ($navigationSummary['capability_id'] ?? ''), 'navigation plan capability must be preserved');
$assert('navigation.update' === ($navigationSummary['operation'] ?? ''), 'navigation plan operation must be preserved');
$assert(['provider'=>'wordpress-classic-menu','target_id'=>88] === ($navigationSummary['target'] ?? []), 'navigation target must be preserved');
$assert(['title'] === ($navigationSummary['fields'] ?? []), 'navigation audit summary must expose changed fields only');
$pendingNavigation = $store->pending('plan_navigation_1', 'smc_contract', 4);
$assert(null !== $pendingNavigation, 'navigation plan must hydrate through generic remote plan store');
$assert('navigation.write' === $pendingNavigation->request->capabilityId, 'hydrated navigation plan must retain capability');
$assert('navigation.update' === $pendingNavigation->request->operation, 'hydrated navigation plan must retain operation');

$seoRequest = new OperationRequest(
    capabilityId: 'seo.write',
    operation: 'seo.update',
    payload: [
        'post_id'=>99,
        'fields'=>['description'=>'SEO description'],
    ],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-4C SEO contract test',
);
$seoPlan = new Plan(
    id: 'plan_seo_1',
    adapter: 'seo-geo-theme-native',
    fingerprint: 'site-fingerprint-seo',
    request: $seoRequest,
    changes: [
        'target'=>['post_id'=>99,'post_type'=>'page'],
        'semantic_fields'=>['description'=>'SEO description'],
        'native_fields'=>['post_excerpt'=>'SEO description'],
        'precondition_fingerprint'=>'seo-before',
        'derived_outputs'=>['open-graph','schema'],
    ],
    snapshot: [
        'target'=>['ID'=>99,'post_type'=>'page','post_title'=>'Page','post_excerpt'=>'Original description'],
        'target_fingerprint'=>'seo-before',
    ],
    reversible: true,
    createdAt: gmdate(DATE_W3C),
);
$seoSummary = $store->save($seoPlan, 'smc_contract', 4);
$assert('seo.write' === ($seoSummary['capability_id'] ?? ''), 'SEO plan capability must be preserved');
$assert('seo.update' === ($seoSummary['operation'] ?? ''), 'SEO plan operation must be preserved');
$assert(['post_id'=>99,'post_type'=>'page'] === ($seoSummary['target'] ?? []), 'SEO target must be preserved');
$assert(['description'] === ($seoSummary['fields'] ?? []), 'SEO audit summary must expose semantic field names');
$pendingSeo = $store->pending('plan_seo_1', 'smc_contract', 4);
$assert(null !== $pendingSeo, 'SEO plan must hydrate through generic remote plan store');
$assert('seo.write' === $pendingSeo->request->capabilityId, 'hydrated SEO plan must retain capability');
$assert('seo.update' === $pendingSeo->request->operation, 'hydrated SEO plan must retain operation');

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
