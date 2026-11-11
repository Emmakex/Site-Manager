<?php
declare(strict_types=1);

/**
 * Portability regression: Site Manager is a CMS-neutral core with WordPress
 * adapters, not a plugin that requires the SEO/GEO Theme to be installed.
 * Run in a fresh PHP process with NO SEO/GEO Theme functions/classes loaded.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ThemeModelInventory;
use SiteManager\Adapters\WordPress\ThemeModelOverride;
use SiteManager\Adapters\WordPress\ThemeTextGovernance;
use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\PlatformIdentity;

function get_option(string $name, mixed $default = false): mixed
{
    return $name === 'page_on_front' ? 21 : $default;
}

function get_post_meta(int $postId, string $key, bool $single): mixed
{
    return '';
}

$GLOBALS['theme_portability_writes'] = 0;

function update_post_meta(int $postId, string $key, mixed $value): bool
{
    ++$GLOBALS['theme_portability_writes'];
    throw new RuntimeException('Theme-specific provider must not write on an unrelated theme.');
}

function assert_portable(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assert_portable(! function_exists('seo_geo_theme_preset_document'), 'unrelated theme fixture must not provide SEO/GEO Theme functions');
assert_portable(! class_exists('SeoGeo\\Theme\\Strategic\\CorporateHomeModelResolver'), 'unrelated theme fixture must not provide Corporate resolver');

$snapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-11-11T00:00:00+00:00',
    fingerprint: 'independent-theme-fixture',
    platform: new PlatformIdentity('wordpress', '6.x', 'wordpress-core'),
    inventory: [
        'theme'=>['name'=>'Other Theme', 'stylesheet'=>'unrelated-theme', 'template'=>'unrelated-theme'],
        'content_types'=>[
            ['name'=>'post', 'builtin'=>true, 'supports'=>['title', 'editor']],
            ['name'=>'page', 'builtin'=>true, 'supports'=>['title', 'editor']],
        ],
        'providers'=>[],
        'urls'=>['https'=>true, 'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);
$adapter = new WordPressAdapter();
$graph = $adapter->capabilities($snapshot);
assert_portable($graph->get('site.read')?->governable === true, 'site.read remains governable with a different theme');
assert_portable($graph->get('content.write')?->governable === true, 'native WordPress content writes remain governable with a different theme');
assert_portable($graph->get('content.write')?->state([]) === 'governable', 'capability discovery does not grant write authority');
assert_portable($graph->get('seo.write')?->governable === false, 'unknown SEO ownership does not imply write authority');
assert_portable(($adapter->diagnostics($snapshot)['ready_for_read'] ?? false) === true, 'generic WordPress diagnostics remain ready');

try {
    (new ThemeModelInventory())->homepage();
    assert_portable(false, 'SEO/GEO semantic inventory must fail closed when theme is absent');
} catch (RuntimeException) {
    // Optional theme capability is unavailable; generic governance remains intact.
}

try {
    (new ThemeTextGovernance())->plan(['hero-lead'=>'Should not apply'], 'Portability check');
    assert_portable(false, 'SEO/GEO theme writer must fail closed when theme is absent');
} catch (LogicException) {
    // No theme-specific plan can be created.
}

$foreignPost = (object) ['ID'=>21, 'post_type'=>'page', 'post_status'=>'publish'];
assert_portable(
    ThemeModelOverride::provide(['source'=>'another-provider'], $foreignPost) === ['source'=>'another-provider'],
    'theme integration never overwrites an existing model provider'
);
assert_portable(ThemeModelOverride::provide(null, $foreignPost) === null, 'no Theme override is invented on another theme');
assert_portable($GLOBALS['theme_portability_writes'] === 0, 'unrelated theme has no semantic writes');

echo "PASS: optional SEO/GEO adapter fails closed; generic Site Manager works with unrelated WordPress theme\n";
