<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use InvalidArgumentException;
use SiteManager\Adapters\WordPress\RankMathSeoGovernanceService;
use SiteManager\Adapters\WordPress\RankMathSeoProviderAdapter;
use SiteManager\Adapters\WordPress\RankMathSeoRuntime;
use SiteManager\Adapters\WordPress\SeoProviderRegistry;
use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

final class FakeRankMathRuntime implements RankMathSeoRuntime
{
    /** @param array<string,mixed> $row */
    public function __construct(public array $row, public bool $available = true, public bool $allowed = true) {}
    public function available(): bool { return $this->available; }
    public function read(int $postId): ?array { return $postId === (int) ($this->row['ID'] ?? 0) ? $this->row : null; }
    public function canEdit(int $postId): bool { return $this->allowed; }
    public function update(int $postId, array $fields): array
    {
        if (! $this->available || ! $this->allowed) { return ['success'=>false,'post_id'=>$postId,'error'=>'denied']; }
        foreach ($fields as $field => $value) { $this->row[$field] = (string) $value; }
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}

$authority = [
    'authority_resolved'=>true,
    'conflict'=>false,
    'authority'=>['id'=>'rank-math','label'=>'Rank Math SEO'],
    'candidates'=>[['id'=>'rank-math']],
    'outputs'=>[],
];
$snapshot = new DiscoverySnapshot(
    schemaVersion:'1', generatedAt:'2026-01-01T00:00:00+00:00', fingerprint:'rank-math-fixture',
    platform:new PlatformIdentity('wordpress','7.x','wordpress-core'),
    inventory:[
        'content_types'=>[['name'=>'page','builtin'=>true],['name'=>'product','builtin'=>false]],
        'providers'=>[['id'=>'rank-math','label'=>'Rank Math SEO']],
        'navigation'=>['providers'=>[]], 'seo_authority'=>$authority,
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'], 'indexability'=>['search_visibility'=>'index'],
    ],
);
$runtime = new FakeRankMathRuntime([
    'ID'=>701,'post_type'=>'product','post_modified_gmt'=>'2026-01-01 00:00:01',
    'rank_math_title'=>'Old title','rank_math_description'=>'Old description','rank_math_canonical_url'=>'',
]);
$provider = new RankMathSeoProviderAdapter(new RankMathSeoGovernanceService($runtime), $runtime);
$adapter = new WordPressAdapter(seoProviders:new SeoProviderRegistry([$provider]));
$graph = $adapter->capabilities($snapshot);
$seo = $graph->get('seo.write');
$assert(true === $seo?->governable, 'available Rank Math runtime must make seo.write governable');
$assert('rank-math' === $seo?->provider, 'Rank Math ownership must be preserved');
$assert('rank-math-post-meta' === ($seo?->metadata['write_mode'] ?? null), 'Rank Math write mode must be explicit');

$request = new OperationRequest(
    capabilityId:'seo.write', operation:'seo.update',
    payload:['post_id'=>701,'fields'=>['title'=>'New title','description'=>'New description','canonical'=>'https://example.test/product/new/']],
    write:true, riskClass:'editorial-review', confirmationClass:'explicit', reason:'SM-3B.3 Rank Math fixture',
);
$plan = $adapter->plan($request,$snapshot,$graph);
$assert('rank-math-post-meta' === $plan->adapter, 'Rank Math plan must use its persisted adapter id');
$assert('New title' === ($plan->changes['provider_fields']['rank_math_title'] ?? null), 'title must map to rank_math_title');
$assert('New description' === ($plan->changes['provider_fields']['rank_math_description'] ?? null), 'description must map to rank_math_description');
$assert('https://example.test/product/new/' === ($plan->changes['provider_fields']['rank_math_canonical_url'] ?? null), 'canonical must map to rank_math_canonical_url');
$assert('Old title' === $runtime->row['rank_math_title'], 'planning must not mutate provider data');

$unconfirmed = $adapter->apply($plan,false);
$assert(false === $unconfirmed->success && 'confirmation-required' === $unconfirmed->status, 'Rank Math writes require confirmation');
$applied = $adapter->apply($plan,true);
$assert(true === $applied->success, 'confirmed Rank Math update must apply');
$assert(true === $adapter->verify($plan,$applied)->verified, 'Rank Math update must verify');
$rolledBack = $adapter->rollback($plan,$applied,true);
$assert(true === $rolledBack->success && 'Old title' === $runtime->row['rank_math_title'], 'Rank Math rollback must restore provider data');

$stale = $adapter->plan($request,$snapshot,$graph);
$runtime->row['rank_math_description'] = 'Human edit after plan';
$staleResult = $adapter->apply($stale,true);
$assert(false === $staleResult->success && 'stale-plan' === $staleResult->status, 'Rank Math must reject provider drift');

$unsupported = false;
try {
    $bad = new OperationRequest('seo.write','seo.update',['post_id'=>701,'fields'=>['indexability'=>'noindex']],true,'editorial-review','explicit');
    $adapter->plan($bad,$snapshot,$graph);
} catch (InvalidArgumentException) { $unsupported = true; }
$assert($unsupported, 'Rank Math adapter must reject robots/indexability until its array contract is explicitly implemented');

$unavailable = new FakeRankMathRuntime($runtime->row,false,true);
$unavailableProvider = new RankMathSeoProviderAdapter(new RankMathSeoGovernanceService($unavailable),$unavailable);
$unavailableGraph = (new WordPressAdapter(seoProviders:new SeoProviderRegistry([$unavailableProvider])))->capabilities($snapshot);
$assert(false === $unavailableGraph->get('seo.write')?->governable, 'Rank Math writes remain blocked if provider runtime is unavailable');

echo "PASS: WordPress SM-3B.3 Rank Math SEO governance\n";
