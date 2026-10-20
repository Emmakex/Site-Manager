<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use InvalidArgumentException;
use SiteManager\Adapters\WordPress\AioseoSeoGovernanceService;
use SiteManager\Adapters\WordPress\AioseoSeoProviderAdapter;
use SiteManager\Adapters\WordPress\AioseoSeoRuntime;
use SiteManager\Adapters\WordPress\SeoProviderRegistry;
use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition,string $message): void {
    if (! $condition) { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); }
};

final class FakeAioseoRuntime implements AioseoSeoRuntime
{
    /** @param array<string,mixed> $row */
    public function __construct(public array $row,public bool $available=true,public bool $allowed=true) {}
    public function available(): bool { return $this->available; }
    public function read(int $postId): ?array { return $postId === (int) ($this->row['ID'] ?? 0) ? $this->row : null; }
    public function canEdit(int $postId): bool { return $this->allowed; }
    public function update(int $postId,array $fields): array
    {
        if (! $this->available || ! $this->allowed) { return ['success'=>false,'post_id'=>$postId,'error'=>'denied']; }
        foreach ($fields as $field=>$value) { $this->row[$field] = (string) $value; }
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}

$authority = [
    'authority_resolved'=>true,'conflict'=>false,
    'authority'=>['id'=>'aioseo','label'=>'All in One SEO'],
    'candidates'=>[['id'=>'aioseo']], 'outputs'=>[],
];
$snapshot = new DiscoverySnapshot(
    schemaVersion:'1',generatedAt:'2026-01-01T00:00:00+00:00',fingerprint:'aioseo-fixture',
    platform:new PlatformIdentity('wordpress','7.x','wordpress-core'),
    inventory:[
        'content_types'=>[['name'=>'page','builtin'=>true,'show_in_rest'=>true],['name'=>'product','builtin'=>false,'show_in_rest'=>true]],
        'providers'=>[['id'=>'aioseo','label'=>'All in One SEO']],
        'navigation'=>['providers'=>[]], 'seo_authority'=>$authority,
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],'indexability'=>['search_visibility'=>'index'],
    ],
);
$runtime = new FakeAioseoRuntime([
    'ID'=>801,'post_type'=>'product','post_modified_gmt'=>'2026-01-01 00:00:01',
    'title'=>'Old AIOSEO title','description'=>'Old AIOSEO description','canonical_url'=>'',
]);
$provider = new AioseoSeoProviderAdapter(new AioseoSeoGovernanceService($runtime),$runtime);
$adapter = new WordPressAdapter(seoProviders:new SeoProviderRegistry([$provider]));
$graph = $adapter->capabilities($snapshot);
$seo = $graph->get('seo.write');
$assert(true === $seo?->governable,'available AIOSEO runtime must make seo.write governable');
$assert('aioseo' === $seo?->provider,'AIOSEO ownership must be preserved');
$assert('aioseo-rest-meta-data' === ($seo?->metadata['write_mode'] ?? null),'AIOSEO write mode must expose official REST metadata contract');

$request = new OperationRequest(
    capabilityId:'seo.write',operation:'seo.update',
    payload:['post_id'=>801,'fields'=>['title'=>'New AIOSEO title','description'=>'New AIOSEO description','canonical'=>'https://example.test/product/new/']],
    write:true,riskClass:'editorial-review',confirmationClass:'explicit',reason:'SM-3B.4 AIOSEO fixture',
);
$plan = $adapter->plan($request,$snapshot,$graph);
$assert('aioseo-rest-meta-data' === $plan->adapter,'AIOSEO plan must use REST metadata adapter id');
$assert('New AIOSEO title' === ($plan->changes['provider_fields']['title'] ?? null),'title must map to aioseo_meta_data.title');
$assert('New AIOSEO description' === ($plan->changes['provider_fields']['description'] ?? null),'description must map to aioseo_meta_data.description');
$assert('https://example.test/product/new/' === ($plan->changes['provider_fields']['canonical_url'] ?? null),'canonical must map to aioseo_meta_data.canonical_url');
$assert('Old AIOSEO title' === $runtime->row['title'],'planning must remain read-only');

$unconfirmed = $adapter->apply($plan,false);
$assert(false === $unconfirmed->success && 'confirmation-required' === $unconfirmed->status,'AIOSEO writes require confirmation');
$applied = $adapter->apply($plan,true);
$assert(true === $applied->success,'confirmed AIOSEO update must apply');
$assert(true === $adapter->verify($plan,$applied)->verified,'AIOSEO update must verify');
$rolledBack = $adapter->rollback($plan,$applied,true);
$assert(true === $rolledBack->success && 'Old AIOSEO title' === $runtime->row['title'],'AIOSEO rollback must restore provider data');

$stale = $adapter->plan($request,$snapshot,$graph);
$runtime->row['description'] = 'Human AIOSEO edit after plan';
$staleResult = $adapter->apply($stale,true);
$assert(false === $staleResult->success && 'stale-plan' === $staleResult->status,'AIOSEO must reject provider drift');

$unsupported = false;
try {
    $bad = new OperationRequest('seo.write','seo.update',['post_id'=>801,'fields'=>['schema'=>'Article']],true,'editorial-review','explicit');
    $adapter->plan($bad,$snapshot,$graph);
} catch (InvalidArgumentException) { $unsupported = true; }
$assert($unsupported,'AIOSEO adapter must reject unverified direct surfaces');

$noRestSnapshot = new DiscoverySnapshot(
    schemaVersion:'1',generatedAt:'2026-01-01T00:00:00+00:00',fingerprint:'aioseo-no-rest',
    platform:new PlatformIdentity('wordpress','7.x','wordpress-core'),
    inventory:[
        'content_types'=>[['name'=>'product','builtin'=>false,'show_in_rest'=>false]],
        'providers'=>[['id'=>'aioseo','label'=>'All in One SEO']], 'navigation'=>['providers'=>[]], 'seo_authority'=>$authority,
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],'routing'=>['permalink_structure'=>'/%postname%/'],'indexability'=>['search_visibility'=>'index'],
    ],
);
$targetBlocked = false;
try { $adapter->plan($request,$noRestSnapshot,$adapter->capabilities($noRestSnapshot)); } catch (InvalidArgumentException) { $targetBlocked = true; }
$assert($targetBlocked,'AIOSEO REST adapter must reject post types without REST support');

echo "PASS: WordPress SM-3B.4 AIOSEO governance\n";
