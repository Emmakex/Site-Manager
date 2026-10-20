<?php
declare(strict_types=1);

$themeRoot = sys_get_temp_dir() . '/site-manager-theme-inspector-' . bin2hex(random_bytes(4));
mkdir($themeRoot, 0777, true);
file_put_contents($themeRoot . '/functions.php', <<<'PHP'
<?php
function demo_theme_seo($post_id) {
    $description = get_post_meta($post_id, '_demo_seo_description', true);
    update_post_meta($post_id, '_demo_seo_description', $description);
    $canonical = get_option('demo_seo_canonical_base', '');
    update_option('demo_seo_canonical_base', $canonical);
    $title = get_the_title($post_id);
    $excerpt = get_post_field('post_excerpt', $post_id);
    $url = get_permalink($post_id);
    $terms = get_the_terms($post_id, 'category');
    return apply_filters('seo_geo_meta_description', $excerpt, $post_id, $title, $url, $terms);
}
add_action('wp_head', 'demo_theme_seo_head');
add_filter('wp_robots', 'demo_theme_robots');
echo '<link rel="canonical" href="https://example.test/">';
echo '<meta property="og:title" content="Example">';
echo '<script type="application/ld+json">{}</script>';
PHP
);

final class SiteManagerFakeTheme
{
    public function __construct(private readonly string $root) {}
    public function get_stylesheet_directory(): string { return $this->root; }
    public function get_template_directory(): string { return $this->root; }
}

$GLOBALS['site_manager_fake_theme_root'] = $themeRoot;
if (! function_exists('wp_get_theme')) {
    function wp_get_theme(): SiteManagerFakeTheme
    {
        return new SiteManagerFakeTheme((string) $GLOBALS['site_manager_fake_theme_root']);
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ThemeSeoContractInspector;
use SiteManager\Adapters\WordPress\ThemeSeoDataSourceTracer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$result = (new ThemeSeoContractInspector())->inspect();
$assert(true === ($result['available'] ?? false), 'theme contract inspection must be available');
$assert(1 === (int) ($result['files_scanned'] ?? 0), 'fixture must scan exactly one PHP file');
$assert(false === ($result['write_adapter_ready'] ?? true), 'inspection must never make writes ready');

$metaKeys = array_column((array) ($result['meta_keys'] ?? []), 'key');
$optionKeys = array_column((array) ($result['option_keys'] ?? []), 'key');
$assert(in_array('_demo_seo_description', $metaKeys, true), 'SEO post meta key must be discovered');
$assert(in_array('demo_seo_canonical_base', $optionKeys, true), 'SEO option key must be discovered');

$hooks = (array) ($result['hook_registrations'] ?? []);
$hookNames = array_map(static fn(array $row): string => (string) ($row['hook'] ?? ''), array_filter($hooks, 'is_array'));
$assert(in_array('wp_head', $hookNames, true), 'wp_head registration must be discovered');
$assert(in_array('wp_robots', $hookNames, true), 'wp_robots registration must be discovered');

$tokens = array_column((array) ($result['output_tokens'] ?? []), 'key');
$assert(in_array('rel="canonical"', $tokens, true), 'canonical output token must be discovered');
$assert(in_array('property="og:', $tokens, true), 'Open Graph output token must be discovered');
$assert(in_array('application/ld+json', $tokens, true), 'JSON-LD output token must be discovered');

$trace = (new ThemeSeoDataSourceTracer())->inspect();
$assert(true === ($trace['available'] ?? false), 'SEO data-source trace must be available');
$assert(1 === (int) ($trace['focus_files_scanned'] ?? 0), 'fixture must trace exactly one SEO-focused PHP file');
$assert(false === ($trace['write_adapter_ready'] ?? true), 'data-source trace must never grant writes');

$apiCalls = array_column((array) ($trace['api_calls'] ?? []), 'key');
foreach (['get_post_meta','get_option','get_the_title','get_post_field','get_permalink','get_the_terms'] as $function) {
    $assert(in_array($function, $apiCalls, true), "{$function} data source must be discovered");
}
$postFields = array_column((array) ($trace['post_fields'] ?? []), 'key');
$assert(in_array('post_excerpt', $postFields, true), 'native post_excerpt source must be discovered');
$filters = array_column((array) ($trace['filter_pipeline'] ?? []), 'key');
$assert(in_array('seo_geo_meta_description', $filters, true), 'SEO filter pipeline must be discovered');
$class = (array) ($trace['classification'] ?? []);
$assert(true === ($class['native_post_content'] ?? false), 'native post content must be classified');
$assert(true === ($class['native_permalink'] ?? false), 'native permalink must be classified');
$assert(true === ($class['native_taxonomy'] ?? false), 'native taxonomy must be classified');
$assert(true === ($class['post_meta'] ?? false), 'post meta use must be classified');
$assert(true === ($class['options'] ?? false), 'option use must be classified');

$encoded = json_encode([$result, $trace]);
$assert(is_string($encoded) && false === strpos($encoded, 'Example'), 'inspection must not persist arbitrary source content');

@unlink($themeRoot . '/functions.php');
@rmdir($themeRoot);

echo "PASS: bounded WordPress theme SEO contract and data-source inspection\n";
