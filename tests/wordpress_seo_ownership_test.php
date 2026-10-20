<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\SeoOwnershipProbe;
use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$probe = new SeoOwnershipProbe();

$themeOwned = $probe->resolve(
    theme: ['name'=>'SEO GEO Starter','stylesheet'=>'seo-geo-theme','template'=>'seo-geo-theme','version'=>'0.1.1'],
    providers: [],
);
$assert(true === ($themeOwned['authority_resolved'] ?? false), 'SEO GEO theme family must resolve a theme-native SEO owner');
$assert('seo-geo-theme' === ($themeOwned['authority']['id'] ?? null), 'SEO GEO theme owner id must remain stable');
$assert(false === ($themeOwned['conflict'] ?? true), 'single theme-native owner must not report conflict');
$assert(false === ($themeOwned['write_adapter_ready'] ?? true), 'ownership discovery must never imply a ready write adapter');
$assert(false === ($themeOwned['safe_to_write_seo_metadata'] ?? true), 'ownership discovery must never grant SEO writes');
$assert(isset($themeOwned['outputs']['canonical']), 'theme-native ownership must inventory canonical output');
$assert(isset($themeOwned['outputs']['schema']), 'theme-native ownership must inventory schema output');

$yoastOnly = $probe->resolve(
    theme: ['name'=>'Generic','stylesheet'=>'generic-theme','template'=>'generic-theme','version'=>'1.0.0'],
    providers: [
        ['id'=>'yoast-seo','label'=>'Yoast SEO','files'=>['wordpress-seo/wp-seo.php'],'versions'=>['26.0'],'state'=>'detected'],
    ],
);
$assert(true === ($yoastOnly['authority_resolved'] ?? false), 'one known SEO provider must resolve ownership');
$assert('yoast-seo' === ($yoastOnly['authority']['id'] ?? null), 'Yoast owner id must be preserved');
$assert(false === ($yoastOnly['safe_to_write_seo_metadata'] ?? true), 'known plugin detection must not imply write safety');

$conflict = $probe->resolve(
    theme: ['name'=>'SEO GEO Starter','stylesheet'=>'seo-geo-theme','template'=>'seo-geo-theme','version'=>'0.1.1'],
    providers: [
        ['id'=>'yoast-seo','label'=>'Yoast SEO','files'=>['wordpress-seo/wp-seo.php'],'versions'=>['26.0'],'state'=>'detected'],
    ],
);
$assert(true === ($conflict['conflict'] ?? false), 'theme-native SEO plus SEO plugin must report ownership conflict');
$assert(false === ($conflict['authority_resolved'] ?? true), 'conflicted SEO ownership must not select an authority silently');
$assert('resolve-seo-owner-conflict' === ($conflict['next_action'] ?? null), 'conflict must require explicit owner resolution');

$unresolved = $probe->resolve(
    theme: ['name'=>'Generic','stylesheet'=>'generic-theme','template'=>'generic-theme','version'=>'1.0.0'],
    providers: [],
);
$assert(false === ($unresolved['authority_resolved'] ?? true), 'unknown SEO ownership must remain unresolved');
$assert('identify-seo-owner' === ($unresolved['next_action'] ?? null), 'unknown ownership must request identification');

$snapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'seo-fixture',
    platform: new PlatformIdentity('wordpress', '7.x', 'wordpress-core'),
    inventory: [
        'content_types'=>[['name'=>'page','builtin'=>true]],
        'providers'=>[],
        'navigation'=>['providers'=>[]],
        'seo_authority'=>$themeOwned,
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);
$graph = (new WordPressAdapter())->capabilities($snapshot);
$seoCapability = $graph->get('seo.write');
$assert(true === $seoCapability?->detected, 'resolved SEO ownership must make seo.write detected');
$assert(true === $seoCapability?->understood, 'resolved non-conflicting SEO ownership must make seo.write understood');
$assert(false === $seoCapability?->governable, 'seo.write must remain blocked until a verified provider write adapter exists');
$assert('seo-geo-theme' === $seoCapability?->provider, 'seo.write capability must identify its resolved provider');

echo "PASS: WordPress SM-3A SEO ownership contract\n";
