<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use InvalidArgumentException;
use SiteManager\Adapters\WordPress\SeoGeoThemeProviderAdapter;
use SiteManager\Adapters\WordPress\SeoProviderRegistry;
use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Adapters\WordPress\YoastSeoGovernanceService;
use SiteManager\Adapters\WordPress\YoastSeoProviderAdapter;
use SiteManager\Adapters\WordPress\YoastSeoRuntime;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

final class FakeYoastRuntime implements YoastSeoRuntime
{
    /** @param array<string,mixed> $row */
    public function __construct(public array $row, public bool $available = true, public bool $allowed = true) {}
    public function available(): bool { return $this->available; }
    public function read(int $postId): ?array { return $postId === (int) ($this->row['ID'] ?? 0) ? $this->row : null; }
    public function canEdit(int $postId): bool { return $this->allowed; }
    public function update(int $postId, array $fields): array
    {
        if (! $this->available || ! $this->allowed) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'unavailable-or-denied'];
        }
        foreach ($fields as $field => $value) { $this->row[$field] = (string) $value; }
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}

$seoAuthority = [
    'authority_resolved'=>true,
    'conflict'=>false,
    'authority'=>['id'=>'yoast-seo','type'=>'plugin','label'=>'Yoast SEO','adapter'=>'yoast-seo-seo'],
    'candidates'=>[['id'=>'yoast-seo','type'=>'plugin','label'=>'Yoast SEO']],
    'write_adapter_ready'=>false,
    'safe_to_write_seo_metadata'=>false,
    'outputs'=>[],
];
$snapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'yoast-provider-fixture',
    platform: new PlatformIdentity('wordpress', '7.x', 'wordpress-core'),
    inventory: [
        'content_types'=>[
            ['name'=>'page','builtin'=>true],
            ['name'=>'product','builtin'=>false],
        ],
        'providers'=>[['id'=>'yoast-seo','label'=>'Yoast SEO','versions'=>['99.0'],'files'=>['wordpress-seo/wp-seo.php']]],
        'navigation'=>['providers'=>[]],
        'seo_authority'=>$seoAuthority,
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);
$runtime = new FakeYoastRuntime([
    'ID'=>501,
    'post_type'=>'product',
    'post_modified_gmt'=>'2026-01-01 00:00:01',
    'title'=>'Old Yoast title',
    'metadesc'=>'Old Yoast description',
    'canonical'=>'',
    'meta-robots-noindex'=>'0',
]);
$yoastProvider = new YoastSeoProviderAdapter(
    governance: new YoastSeoGovernanceService($runtime),
    runtime: $runtime,
);
$registry = new SeoProviderRegistry([
    new SeoGeoThemeProviderAdapter(),
    $yoastProvider,
]);
$adapter = new WordPressAdapter(seoProviders: $registry);
$graph = $adapter->capabilities($snapshot);
$seo = $graph->get('seo.write');
$assert(true === $seo?->detected, 'Yoast ownership must be detected');
$assert(true === $seo?->understood, 'single Yoast ownership must be understood');
$assert(true === $seo?->governable, 'available Yoast metadata API must make seo.write governable');
$assert('yoast-seo' === $seo?->provider, 'seo.write must retain Yoast ownership');
$assert('yoast-meta-api' === ($seo?->metadata['write_mode'] ?? null), 'Yoast capability must expose provider API write mode');
$assert('yoast-seo-meta-api' === ($seo?->metadata['plan_adapter'] ?? null), 'Yoast capability must expose its persisted plan adapter id');

$request = new OperationRequest(
    capabilityId: 'seo.write',
    operation: 'seo.update',
    payload: ['post_id'=>501,'fields'=>[
        'title'=>'New Yoast title',
        'description'=>'New Yoast description',
        'canonical'=>'https://example.test/product/new/',
        'indexability'=>'noindex',
    ]],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-3B.2 Yoast contract fixture',
);
$plan = $adapter->plan($request, $snapshot, $graph);
$assert('yoast-seo-meta-api' === $plan->adapter, 'Yoast plan must route through the Yoast provider adapter');
$assert('New Yoast title' === ($plan->changes['provider_fields']['title'] ?? null), 'semantic title must map to Yoast title');
$assert('New Yoast description' === ($plan->changes['provider_fields']['metadesc'] ?? null), 'semantic description must map to Yoast metadesc');
$assert('https://example.test/product/new/' === ($plan->changes['provider_fields']['canonical'] ?? null), 'canonical must map to Yoast canonical');
$assert('1' === ($plan->changes['provider_fields']['meta-robots-noindex'] ?? null), 'noindex must map to Yoast value 1');
$assert('Old Yoast title' === $runtime->row['title'], 'planning must remain read-only');

$unconfirmed = $adapter->apply($plan, false);
$assert(false === $unconfirmed->success && 'confirmation-required' === $unconfirmed->status, 'Yoast apply must require explicit confirmation');
$applied = $adapter->apply($plan, true);
$assert(true === $applied->success, 'confirmed Yoast update must apply');
$assert('New Yoast title' === $runtime->row['title'], 'Yoast title must update');
$assert('1' === $runtime->row['meta-robots-noindex'], 'Yoast indexability must update');
$verified = $adapter->verify($plan, $applied);
$assert(true === $verified->verified, 'Yoast update must verify');
$rolledBack = $adapter->rollback($plan, $applied, true);
$assert(true === $rolledBack->success, 'Yoast update must roll back');
$assert('Old Yoast title' === $runtime->row['title'], 'rollback must restore Yoast title');
$assert('0' === $runtime->row['meta-robots-noindex'], 'rollback must restore Yoast indexability');

$stale = $adapter->plan($request, $snapshot, $graph);
$runtime->row['metadesc'] = 'Human Yoast edit after plan';
$staleResult = $adapter->apply($stale, true);
$assert(false === $staleResult->success && 'stale-plan' === $staleResult->status, 'Yoast apply must reject provider-field drift after planning');
$assert('Human Yoast edit after plan' === $runtime->row['metadesc'], 'stale plan must preserve later Yoast edits');

$unsupportedCaught = false;
try {
    $bad = new OperationRequest(
        capabilityId: 'seo.write',
        operation: 'seo.update',
        payload: ['post_id'=>501,'fields'=>['schema'=>'Article']],
        write: true,
        riskClass: 'editorial-review',
        confirmationClass: 'explicit',
    );
    $adapter->plan($bad, $snapshot, $graph);
} catch (InvalidArgumentException) {
    $unsupportedCaught = true;
}
$assert($unsupportedCaught, 'Yoast adapter must reject SEO surfaces it does not explicitly own for writes');

$unavailableRuntime = new FakeYoastRuntime($runtime->row, false, true);
$unavailableRegistry = new SeoProviderRegistry([
    new YoastSeoProviderAdapter(
        governance: new YoastSeoGovernanceService($unavailableRuntime),
        runtime: $unavailableRuntime,
    ),
]);
$unavailableGraph = (new WordPressAdapter(seoProviders: $unavailableRegistry))->capabilities($snapshot);
$assert(false === $unavailableGraph->get('seo.write')?->governable, 'Yoast ownership must remain blocked when WPSEO_Meta API is unavailable');

$conflictSnapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'yoast-conflict',
    platform: new PlatformIdentity('wordpress', '7.x', 'wordpress-core'),
    inventory: [
        'content_types'=>[['name'=>'page','builtin'=>true]],
        'providers'=>[['id'=>'yoast-seo','label'=>'Yoast SEO']],
        'navigation'=>['providers'=>[]],
        'seo_authority'=>[
            'authority_resolved'=>false,
            'conflict'=>true,
            'authority'=>null,
            'candidates'=>[['id'=>'yoast-seo'],['id'=>'seo-geo-theme']],
            'outputs'=>[],
        ],
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);
$assert(false === $adapter->capabilities($conflictSnapshot)->get('seo.write')?->governable, 'SEO ownership conflict must block Yoast writes');

echo "PASS: WordPress SM-3B.2 Yoast SEO governance\n";
