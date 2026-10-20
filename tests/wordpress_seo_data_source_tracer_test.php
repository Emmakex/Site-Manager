<?php
declare(strict_types=1);

$themeRoot = sys_get_temp_dir() . '/site-manager-seo-source-tracer-' . bin2hex(random_bytes(4));
mkdir($themeRoot . '/inc/seo', 0777, true);
file_put_contents($themeRoot . '/inc/seo/NativeSeoMetadata.php', <<<'PHP'
<?php
final class DemoNativeSeoMetadata {
    public function build($post) {
        $title = get_the_title($post);
        $excerpt = get_the_excerpt($post);
        $url = get_permalink($post);
        $terms = get_the_terms($post, 'category');
        $site = get_bloginfo('name');
        $meta = get_post_meta($post->ID, '_unrelated_runtime_flag', true);
        return apply_filters('seo_geo_meta_description', $excerpt, $post);
    }
}
PHP
);

final class SiteManagerTracerFakeTheme
{
    public function __construct(private readonly string $root) {}
    public function get_stylesheet_directory(): string { return $this->root; }
    public function get_template_directory(): string { return $this->root; }
}

$GLOBALS['site_manager_tracer_theme_root'] = $themeRoot;
if (! function_exists('wp_get_theme')) {
    function wp_get_theme(): SiteManagerTracerFakeTheme
    {
        return new SiteManagerTracerFakeTheme((string) $GLOBALS['site_manager_tracer_theme_root']);
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ThemeSeoDataSourceTracer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$result = (new ThemeSeoDataSourceTracer())->inspect();
$assert(true === ($result['available'] ?? false), 'SEO source trace must be available');
$assert(1 === (int) ($result['focus_files_scanned'] ?? 0), 'fixture must scan one SEO-focused file');
$class = (array) ($result['classification'] ?? []);
$assert(true === ($class['native_post_content'] ?? false), 'native post content source must be detected');
$assert(true === ($class['native_permalink'] ?? false), 'native permalink source must be detected');
$assert(true === ($class['native_taxonomy'] ?? false), 'native taxonomy source must be detected');
$assert(true === ($class['site_identity'] ?? false), 'site identity source must be detected');
$assert(true === ($class['post_meta'] ?? false), 'post meta API reference must be detected without reading its value');
$assert(false === ($class['acf'] ?? true), 'ACF must remain false when not referenced');
$assert(false === ($class['theme_mod'] ?? true), 'theme mods must remain false when not referenced');
$assert(false === ($class['custom_database'] ?? true), 'custom database must remain false when not referenced');
$assert(false === ($result['write_adapter_ready'] ?? true), 'source tracing alone must never grant writes');

$apis = array_column((array) ($result['api_calls'] ?? []), 'key');
$assert(in_array('get_the_title', $apis, true), 'get_the_title must be inventoried');
$assert(in_array('get_permalink', $apis, true), 'get_permalink must be inventoried');
$assert(in_array('get_post_meta', $apis, true), 'get_post_meta must be inventoried');
$filters = array_column((array) ($result['filter_pipeline'] ?? []), 'key');
$assert(in_array('seo_geo_meta_description', $filters, true), 'SEO provider filter pipeline must be inventoried');

$encoded = json_encode($result);
$assert(is_string($encoded) && false === strpos($encoded, '_unrelated_runtime_flag'), 'source trace must not persist arbitrary argument values');

@unlink($themeRoot . '/inc/seo/NativeSeoMetadata.php');
@rmdir($themeRoot . '/inc/seo');
@rmdir($themeRoot . '/inc');
@rmdir($themeRoot);

echo "PASS: bounded WordPress SEO data-source trace\n";
