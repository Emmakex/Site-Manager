<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\NavigationGovernanceService;
use SiteManager\Adapters\WordPress\NavigationRuntime;
use SiteManager\Adapters\WordPress\NativeNavigationRuntime;
use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

final class FakeNavigationRuntime implements NavigationRuntime
{
    /** @param array<string,array<int,array<string,mixed>>> $targets */
    public function __construct(public array $targets, public bool $allowed = true) {}

    public function read(string $provider, int $targetId): ?array
    {
        return $this->targets[$provider][$targetId] ?? null;
    }

    public function canEdit(string $provider, int $targetId): bool
    {
        return $this->allowed && isset($this->targets[$provider][$targetId]);
    }

    public function sanitize(string $provider, string $field, mixed $value): mixed
    {
        if ('classes' === $field) {
            return array_values(array_filter(array_map('strval', is_array($value) ? $value : (preg_split('/\s+/', (string) $value) ?: []))));
        }
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ('target' === $field) { return '_blank' === $value ? '_blank' : ''; }
        return $value;
    }

    public function update(string $provider, int $targetId, array $fields): array
    {
        if (! $this->canEdit($provider, $targetId)) {
            return ['success'=>false,'target_id'=>$targetId,'error'=>'denied'];
        }
        foreach ($fields as $field => $value) {
            $this->targets[$provider][$targetId][$field] = $value;
        }
        $current = (string) ($this->targets[$provider][$targetId]['modified_gmt'] ?? '2026-01-01 00:00:00');
        $seconds = ((int) substr($current, -2) + 1) % 60;
        $this->targets[$provider][$targetId]['modified_gmt'] = '2026-01-01 00:00:' . str_pad((string) $seconds, 2, '0', STR_PAD_LEFT);
        return ['success'=>true,'target_id'=>$targetId,'error'=>''];
    }
}

$snapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'site-navigation-fingerprint',
    platform: new PlatformIdentity('wordpress', '7.x', 'wordpress-core'),
    inventory: [
        'content_types'=>[['name'=>'page','builtin'=>true]],
        'navigation'=>[
            'providers'=>[
                ['id'=>NativeNavigationRuntime::CLASSIC,'label'=>'Classic','actionable'=>true,'target_count'=>1],
                ['id'=>NativeNavigationRuntime::BLOCK,'label'=>'Block','actionable'=>true,'target_count'=>1],
            ],
        ],
        'providers'=>[],
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);

$runtime = new FakeNavigationRuntime([
    NativeNavigationRuntime::CLASSIC => [
        10 => [
            'provider'=>NativeNavigationRuntime::CLASSIC,
            'ID'=>10,
            'menu_id'=>2,
            'type'=>'custom',
            'object'=>'custom',
            'object_id'=>10,
            'title'=>'Home',
            'url'=>'https://example.test/',
            'description'=>'',
            'attr_title'=>'',
            'target'=>'',
            'classes'=>['menu-item'],
            'xfn'=>'',
            'menu_item_parent'=>0,
            'menu_order'=>1,
            'status'=>'publish',
            'modified_gmt'=>'2026-01-01 00:00:01',
        ],
    ],
    NativeNavigationRuntime::BLOCK => [
        20 => [
            'provider'=>NativeNavigationRuntime::BLOCK,
            'ID'=>20,
            'title'=>'Primary navigation',
            'content'=>'<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
            'status'=>'publish',
            'modified_gmt'=>'2026-01-01 00:00:01',
        ],
    ],
]);
$service = new NavigationGovernanceService($runtime);
$graph = (new WordPressAdapter())->capabilities($snapshot);
$assert(true === $graph->get('navigation.write')?->governable, 'navigation.write must be governable when a native actionable provider is discovered');
$assert('governable' === $graph->get('navigation.write')?->state([]), 'navigation.write must remain unauthorized without navigation.write scope');
$assert('authorized' === $graph->get('navigation.write')?->state(['navigation.write']), 'navigation.write must authorize with its explicit scope');

$classicRequest = new OperationRequest(
    capabilityId: 'navigation.write',
    operation: 'navigation.update',
    payload: [
        'provider'=>NativeNavigationRuntime::CLASSIC,
        'target_id'=>10,
        'fields'=>['title'=>'Inicio','url'=>'https://example.test/inicio','target'=>'_blank'],
    ],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-2B classic navigation acceptance fixture',
);
$classicPlan = $service->plan($classicRequest, $snapshot, $graph);
$assert(true === $classicPlan->reversible, 'classic navigation plan must be reversible');
$assert('Home' === ($classicPlan->snapshot['target']['title'] ?? null), 'classic plan must snapshot original title');
$assert('Home' === $runtime->targets[NativeNavigationRuntime::CLASSIC][10]['title'], 'classic planning must not mutate navigation');

$notConfirmed = $service->apply($classicPlan, false);
$assert(false === $notConfirmed->success && 'confirmation-required' === $notConfirmed->status, 'classic apply must require explicit confirmation');
$applied = $service->apply($classicPlan, true);
$assert(true === $applied->success && 'Inicio' === $runtime->targets[NativeNavigationRuntime::CLASSIC][10]['title'], 'confirmed classic plan must apply');
$assert(true === $service->verify($classicPlan, $applied)->verified, 'classic navigation apply must verify');
$rolledBack = $service->rollback($classicPlan, $applied, true);
$assert(true === $rolledBack->success && 'Home' === $runtime->targets[NativeNavigationRuntime::CLASSIC][10]['title'], 'classic rollback must restore original title');
$assert('https://example.test/' === $runtime->targets[NativeNavigationRuntime::CLASSIC][10]['url'], 'classic rollback must restore original URL');

$staleClassic = $service->plan($classicRequest, $snapshot, $graph);
$runtime->targets[NativeNavigationRuntime::CLASSIC][10]['title'] = 'Human navigation edit';
$runtime->targets[NativeNavigationRuntime::CLASSIC][10]['modified_gmt'] = '2026-01-01 00:00:59';
$staleResult = $service->apply($staleClassic, true);
$assert(false === $staleResult->success && 'stale-plan' === $staleResult->status, 'classic apply must reject target drift');
$assert('Human navigation edit' === $runtime->targets[NativeNavigationRuntime::CLASSIC][10]['title'], 'stale classic rejection must preserve human edit');

$blockRequest = new OperationRequest(
    capabilityId: 'navigation.write',
    operation: 'navigation.update',
    payload: [
        'provider'=>NativeNavigationRuntime::BLOCK,
        'target_id'=>20,
        'fields'=>['title'=>'Main navigation','content'=>'<!-- wp:navigation-link {"label":"Start","url":"/"} /-->'],
    ],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-2B block navigation acceptance fixture',
);
$blockPlan = $service->plan($blockRequest, $snapshot, $graph);
$assert('Primary navigation' === ($blockPlan->snapshot['target']['title'] ?? null), 'block plan must snapshot original title');
$blockApplied = $service->apply($blockPlan, true);
$assert(true === $blockApplied->success && 'Main navigation' === $runtime->targets[NativeNavigationRuntime::BLOCK][20]['title'], 'confirmed block plan must apply');
$assert(true === $service->verify($blockPlan, $blockApplied)->verified, 'block navigation apply must verify');
$blockRolledBack = $service->rollback($blockPlan, $blockApplied, true);
$assert(true === $blockRolledBack->success && 'Primary navigation' === $runtime->targets[NativeNavigationRuntime::BLOCK][20]['title'], 'block rollback must restore original title');

echo "PASS: WordPress SM-2B navigation governance contract\n";
