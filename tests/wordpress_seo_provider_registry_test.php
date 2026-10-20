<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\SeoProviderRegistry;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$snapshot = static function (array $seo): DiscoverySnapshot {
    return new DiscoverySnapshot(
        schemaVersion:'1', generatedAt:'2026-01-01T00:00:00+00:00', fingerprint:'seo-provider-registry-fixture',
        platform:new PlatformIdentity('wordpress','7.x','wordpress-core'), inventory:['seo_authority'=>$seo],
    );
};

$readyTheme = [
    'authority_resolved'=>true,'conflict'=>false,
    'authority'=>['id'=>'seo-geo-theme','label'=>'SEO/GEO Theme'],
    'write_adapter_ready'=>true,'safe_to_write_seo_metadata'=>true,'write_mode'=>'semantic-native-sources',
    'writable_inputs'=>['title'=>['source'=>'post_title'],'description'=>['source'=>'post_excerpt']],
    'derived_outputs'=>['canonical'=>['sources'=>['permalink']]],'outputs'=>[],
];

$registry = new SeoProviderRegistry();
$assert(['rank-math','seo-geo-theme','yoast-seo'] === $registry->ids(), 'default registry must contain Rank Math, theme-native and Yoast adapters');
$themeAdapter = $registry->resolve($snapshot($readyTheme));
$assert('seo-geo-theme' === $themeAdapter?->id(), 'resolved SEO/GEO theme ownership must resolve its adapter');
$assert('seo-geo-theme-native' === $themeAdapter?->planAdapterId(), 'theme plan adapter id must remain stable');
$themeReady = $themeAdapter?->readiness($snapshot($readyTheme)) ?? [];
$assert(true === ($themeReady['ready'] ?? false), 'theme provider readiness must remain green');

$yoast = ['authority_resolved'=>true,'conflict'=>false,'authority'=>['id'=>'yoast-seo','label'=>'Yoast SEO']];
$yoastAdapter = $registry->resolve($snapshot($yoast));
$assert('yoast-seo' === $yoastAdapter?->id(), 'Yoast ownership must resolve its registered adapter');
$yoastReady = $yoastAdapter?->readiness($snapshot($yoast)) ?? [];
$assert(false === ($yoastReady['ready'] ?? true), 'Yoast adapter must remain not-ready when WPSEO_Meta runtime is unavailable');

$rankMath = ['authority_resolved'=>true,'conflict'=>false,'authority'=>['id'=>'rank-math','label'=>'Rank Math SEO']];
$rankMathAdapter = $registry->resolve($snapshot($rankMath));
$assert('rank-math' === $rankMathAdapter?->id(), 'Rank Math ownership must resolve its registered adapter');
$rankReady = $rankMathAdapter?->readiness($snapshot($rankMath)) ?? [];
$assert(false === ($rankReady['ready'] ?? true), 'Rank Math adapter must remain not-ready outside a loaded Rank Math runtime');

$conflict = [
    'authority_resolved'=>false,'conflict'=>true,'authority'=>null,
    'candidates'=>[['id'=>'seo-geo-theme'],['id'=>'yoast-seo']],
];
$assert(null === $registry->resolve($snapshot($conflict)), 'conflicted SEO ownership must never resolve a writer');

$plans = [
    'seo-geo-theme-native'=>'seo-geo-theme',
    'yoast-seo-meta-api'=>'yoast-seo',
    'rank-math-post-meta'=>'rank-math',
];
foreach ($plans as $planAdapter => $providerId) {
    $plan = new Plan(
        id:'plan-' . $providerId, adapter:$planAdapter, fingerprint:'fixture',
        request:new OperationRequest('seo.write','seo.update',[],true,'editorial-review','explicit'),
        changes:[], snapshot:[], reversible:true, createdAt:'2026-01-01T00:00:00+00:00',
    );
    $assert($providerId === $registry->resolvePlan($plan)?->id(), 'persisted plan adapter must resolve ' . $providerId);
}

echo "PASS: WordPress SM-3B SEO provider registry contract\n";
