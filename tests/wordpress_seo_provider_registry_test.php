<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\SeoProviderRegistry;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition,string $message): void {
    if (! $condition) { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); }
};
$snapshot = static function (array $seo): DiscoverySnapshot {
    return new DiscoverySnapshot(
        schemaVersion:'1',generatedAt:'2026-01-01T00:00:00+00:00',fingerprint:'seo-provider-registry-fixture',
        platform:new PlatformIdentity('wordpress','7.x','wordpress-core'),inventory:['seo_authority'=>$seo],
    );
};

$readyTheme = [
    'authority_resolved'=>true,'conflict'=>false,'authority'=>['id'=>'seo-geo-theme','label'=>'SEO/GEO Theme'],
    'write_adapter_ready'=>true,'safe_to_write_seo_metadata'=>true,'write_mode'=>'semantic-native-sources',
    'writable_inputs'=>['title'=>['source'=>'post_title'],'description'=>['source'=>'post_excerpt']],
    'derived_outputs'=>['canonical'=>['sources'=>['permalink']]],'outputs'=>[],
];

$registry = new SeoProviderRegistry();
$assert(['aioseo','rank-math','seo-geo-theme','yoast-seo'] === $registry->ids(),'default registry must contain AIOSEO, Rank Math, theme-native and Yoast adapters');
$themeAdapter = $registry->resolve($snapshot($readyTheme));
$assert('seo-geo-theme' === $themeAdapter?->id(),'theme ownership must resolve its adapter');
$assert(true === (($themeAdapter?->readiness($snapshot($readyTheme))['ready'] ?? false)),'theme provider must remain ready');

$runtimePending = [
    'yoast-seo'=>'Yoast SEO',
    'rank-math'=>'Rank Math SEO',
    'aioseo'=>'All in One SEO',
];
foreach ($runtimePending as $providerId=>$label) {
    $seo = ['authority_resolved'=>true,'conflict'=>false,'authority'=>['id'=>$providerId,'label'=>$label]];
    $provider = $registry->resolve($snapshot($seo));
    $assert($providerId === $provider?->id(),$label . ' ownership must resolve its registered adapter');
    $readiness = $provider?->readiness($snapshot($seo)) ?? [];
    $assert(false === ($readiness['ready'] ?? true),$label . ' must remain not-ready outside its loaded WordPress runtime');
}

$conflict = ['authority_resolved'=>false,'conflict'=>true,'authority'=>null,'candidates'=>[['id'=>'seo-geo-theme'],['id'=>'yoast-seo']]];
$assert(null === $registry->resolve($snapshot($conflict)),'conflicted SEO ownership must never resolve a writer');

$plans = [
    'seo-geo-theme-native'=>'seo-geo-theme',
    'yoast-seo-meta-api'=>'yoast-seo',
    'rank-math-post-meta'=>'rank-math',
    'aioseo-rest-meta-data'=>'aioseo',
];
foreach ($plans as $planAdapter=>$providerId) {
    $plan = new Plan(
        id:'plan-' . $providerId,adapter:$planAdapter,fingerprint:'fixture',
        request:new OperationRequest('seo.write','seo.update',[],true,'editorial-review','explicit'),
        changes:[],snapshot:[],reversible:true,createdAt:'2026-01-01T00:00:00+00:00',
    );
    $assert($providerId === $registry->resolvePlan($plan)?->id(),'persisted plan adapter must resolve ' . $providerId);
}

echo "PASS: WordPress SM-3B SEO provider registry contract\n";
