<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Core\Governance\GovernancePolicy;
use SiteManager\Core\Model\Capability;
use SiteManager\Core\Model\CapabilityGraph;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Planning\PlanFactory;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$capability = new Capability(
    id: 'content.page.write',
    provider: 'fixture-adapter',
    detected: true,
    understood: true,
    governable: true,
    operations: ['page.update'],
    requiredScopes: ['content.write'],
);
$graph = new CapabilityGraph([$capability]);

$assert('governable' === $capability->state([]), 'governable must remain distinct from authorization');
$assert('authorized' === $capability->state(['content.write']), 'authorized requires the declared scope');
$assert(1 === $graph->summarize(['content.write'])['authorized'], 'graph summary must count authorized capability');

$request = new OperationRequest(
    capabilityId: 'content.page.write',
    operation: 'page.update',
    payload: ['title'=>'Example'],
    write: true,
    riskClass: 'editorial',
    confirmationClass: 'explicit',
    reason: 'contract test',
);

$policy = new GovernancePolicy();
$missingScope = $policy->evaluate($request, $graph, [], true);
$assert(! $missingScope->allowed, 'missing scope must block execution');

$unconfirmed = $policy->evaluate($request, $graph, ['content.write'], false);
$assert(! $unconfirmed->allowed, 'explicit confirmation must block unconfirmed write');

$allowed = $policy->evaluate($request, $graph, ['content.write'], true);
$assert($allowed->allowed, 'supported, scoped and confirmed operation must be allowed');

$factory = new PlanFactory();
$planA = $factory->create('fixture-adapter', 'site-fingerprint', $request, ['after'=>['b'=>2,'a'=>1]], ['before'=>['title'=>'Old']], true, '2026-01-01T00:00:00+00:00');
$planB = $factory->create('fixture-adapter', 'site-fingerprint', $request, ['after'=>['a'=>1,'b'=>2]], ['before'=>['title'=>'Old']], true, '2027-01-01T00:00:00+00:00');
$assert($planA->id === $planB->id, 'plan id must be stable across associative key order and creation time');
$assert(str_starts_with($planA->id, 'sm-plan-'), 'plan id must use neutral Site Manager prefix');

$blocked = new Capability('builder.write', 'fixture-builder', true, true, false, ['builder.update'], ['builder.write'], ['No safe adapter']);
$blockedGraph = new CapabilityGraph([$blocked]);
$blockedDecision = $policy->evaluate(new OperationRequest('builder.write','builder.update',[],true,'high','explicit'), $blockedGraph, ['builder.write'], true);
$assert(! $blockedDecision->allowed, 'detected provider without safe adapter must remain blocked');

echo "PASS: Site Manager core contracts\n";
