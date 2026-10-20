<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\SeoProviderRegistry;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$snapshot = static function (array $seo): DiscoverySnapshot {
    return new DiscoverySnapshot(
        schemaVersion: '1',
        generatedAt: '2026-01-01T00:00:00+00:00',
        fingerprint: 'seo-provider-registry-fixture',
        platform: new PlatformIdentity('wordpress', '7.x', 'wordpress-core'),
        inventory: ['seo_authority'=>$seo],
    );
};

$readyTheme = [
    'authority_resolved'=>true,
    'conflict'=>false,
    'authority'=>['id'=>'seo-geo-theme','label'=>'SEO/GEO Theme'],
    'write_adapter_ready'=>true,
    'safe_to_write_seo_metadata'=>true,
    'write_mode'=>'semantic-native-sources',
    'writable_inputs'=>['title'=>['source'=>'post_title'],'description'=>['source'=>'post_excerpt']],
    'derived_outputs'=>['canonical'=>['sources'=>['permalink']]],
    'outputs'=>[],
];

$registry = new SeoProviderRegistry();
$assert(['seo-geo-theme','yoast-seo'] === $registry->ids(), 'default registry must contain theme-native and Yoast provider adapters');
$themeAdapter = $registry->resolve($snapshot($readyTheme));
$assert(null !== $themeAdapter, 'resolved SEO/GEO theme ownership must resolve a provider adapter');
$assert('seo-geo-theme' === $themeAdapter?->id(), 'resolved provider id must remain stable');
$assert('seo-geo-theme-native' === $themeAdapter?->planAdapterId(), 'provider plan adapter id must remain stable');
$readiness = $themeAdapter?->readiness($snapshot($readyTheme)) ?? [];
$assert(true === ($readiness['ready'] ?? false), 'provider readiness must reflect the proven runtime contract');
$assert('semantic-native-sources' === ($readiness['write_mode'] ?? null), 'provider readiness must expose its real write mode');

$yoastPending = [
    'authority_resolved'=>true,
    'conflict'=>false,
    'authority'=>['id'=>'yoast-seo','label'=>'Yoast SEO'],
    'write_adapter_ready'=>false,
    'safe_to_write_seo_metadata'=>false,
];
$yoastAdapter = $registry->resolve($snapshot($yoastPending));
$assert('yoast-seo' === $yoastAdapter?->id(), 'detected Yoast ownership must resolve its registered adapter');
$assert(false === (($yoastAdapter?->readiness($snapshot($yoastPending))['ready'] ?? true)), 'Yoast adapter must remain not-ready when WPSEO_Meta runtime API is unavailable');

$conflict = [
    'authority_resolved'=>false,
    'conflict'=>true,
    'authority'=>null,
    'candidates'=>[['id'=>'seo-geo-theme'],['id'=>'yoast-seo']],
];
$assert(null === $registry->resolve($snapshot($conflict)), 'conflicted SEO ownership must never resolve a writer');

$themePlan = new Plan(
    id: 'plan-provider-registry-theme',
    adapter: 'seo-geo-theme-native',
    fingerprint: 'fixture',
    request: new OperationRequest('seo.write', 'seo.update', [], true, 'editorial-review', 'explicit'),
    changes: [],
    snapshot: [],
    reversible: true,
    createdAt: '2026-01-01T00:00:00+00:00',
);
$assert('seo-geo-theme' === $registry->resolvePlan($themePlan)?->id(), 'theme apply/verify/rollback routing must resolve provider from persisted plan adapter id');

$yoastPlan = new Plan(
    id: 'plan-provider-registry-yoast',
    adapter: 'yoast-seo-meta-api',
    fingerprint: 'fixture',
    request: new OperationRequest('seo.write', 'seo.update', [], true, 'editorial-review', 'explicit'),
    changes: [],
    snapshot: [],
    reversible: true,
    createdAt: '2026-01-01T00:00:00+00:00',
);
$assert('yoast-seo' === $registry->resolvePlan($yoastPlan)?->id(), 'Yoast apply/verify/rollback routing must resolve provider from persisted plan adapter id');

echo "PASS: WordPress SM-3B SEO provider registry contract\n";
