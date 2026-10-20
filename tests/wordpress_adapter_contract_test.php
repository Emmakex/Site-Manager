<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$snapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'fixture-fingerprint',
    platform: new PlatformIdentity('wordpress', '6.x', 'wordpress-core'),
    inventory: [
        'content_types'=>[
            ['name'=>'post','builtin'=>true,'supports'=>['title','editor']],
            ['name'=>'page','builtin'=>true,'supports'=>['title','editor']],
        ],
        'providers'=>[
            ['id'=>'woocommerce','label'=>'WooCommerce','files'=>['woocommerce/woocommerce.php'],'versions'=>['10.0.0'],'state'=>'detected'],
        ],
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);

$serialized = $snapshot->jsonSerialize();
$assert(is_array($serialized['platform'] ?? null), 'serialized platform identity must be plain dashboard-safe data');
$assert('wordpress' === ($serialized['platform']['platform'] ?? null), 'serialized platform identity must retain platform id');

$adapter = new WordPressAdapter();
$graph = $adapter->capabilities($snapshot);

$assert(null !== $graph->get('site.read'), 'WordPress adapter must expose site.read');
$assert(true === $graph->get('site.read')?->governable, 'site.read must remain governable');
$assert(true === $graph->get('content.write')?->governable, 'native post/page writes must become governable in SM-2A');
$assert('governable' === $graph->get('content.write')?->state([]), 'content.write must remain scope-gated without authorization');
$assert(false === $graph->get('navigation.write')?->governable, 'navigation writes must remain blocked until SM-2B');
$assert(false === $graph->get('seo.write')?->governable, 'SEO writes must remain blocked until provider ownership is resolved');
$assert(false === $graph->get('provider.woocommerce')?->governable, 'detected provider must not imply governance');
$assert('blocked' === $graph->get('provider.woocommerce')?->state([]), 'provider without adapter must report blocked state');

$diagnostics = $adapter->diagnostics($snapshot);
$assert(true === ($diagnostics['ready_for_read'] ?? false), 'read diagnostics should be ready when REST is present');
$assert(true === ($diagnostics['content_write_guard']['stale_plan_protection'] ?? false), 'content write diagnostics must expose stale plan protection');
$assert(true === ($diagnostics['content_write_guard']['rollback'] ?? false), 'content write diagnostics must expose rollback support');

echo "PASS: WordPress adapter contract through SM-2A\n";
