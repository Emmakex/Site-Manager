<?php
declare(strict_types=1);

$themeRoot = sys_get_temp_dir() . '/site-manager-seo-provider-ready-' . bin2hex(random_bytes(4));
mkdir($themeRoot . '/inc/seo', 0777, true);
file_put_contents($themeRoot . '/inc/seo/Runtime.php', <<<'PHP'
<?php
final class DemoSeoRuntime {
    public function metadata($post) {
        $title = get_the_title($post);
        $description = get_the_excerpt($post);
        $canonical = get_permalink($post);
        $terms = get_the_terms($post, 'category');
        $site = get_bloginfo('name');
        return apply_filters('seo_geo_meta_description', $description, $post);
    }
}
add_filter('pre_get_document_title', static fn($title) => $title);
add_filter('wp_robots', static fn($robots) => $robots);
PHP
);

final class SiteManagerSeoReadyFakeTheme
{
    public function __construct(private readonly string $root) {}
    public function get_stylesheet_directory(): string { return $this->root; }
    public function get_template_directory(): string { return $this->root; }
}

$GLOBALS['site_manager_seo_ready_theme_root'] = $themeRoot;
if (! function_exists('wp_get_theme')) {
    function wp_get_theme(): SiteManagerSeoReadyFakeTheme
    {
        return new SiteManagerSeoReadyFakeTheme((string) $GLOBALS['site_manager_seo_ready_theme_root']);
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\SeoOwnershipProbe;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$probe = new SeoOwnershipProbe();
$result = $probe->inspect(
    theme: ['name'=>'SEO GEO Starter','stylesheet'=>'seo-geo-theme','template'=>'seo-geo-theme','version'=>'0.1.1'],
    providers: [],
);

$assert(true === ($result['authority_resolved'] ?? false), 'SEO/GEO Theme authority must resolve');
$assert('seo-geo-theme' === ($result['authority']['id'] ?? null), 'SEO/GEO Theme owner id must remain stable');
$assert(true === ($result['write_adapter_ready'] ?? false), 'bounded native-derived evidence must enable the semantic writer');
$assert(true === ($result['safe_to_write_seo_metadata'] ?? false), 'evidenced semantic writer must make seo.write governable');
$assert('semantic-native-sources' === ($result['write_mode'] ?? null), 'writer mode must identify native semantic sources');
$assert('post_title' === ($result['writable_inputs']['title']['source'] ?? null), 'title must map to post_title');
$assert('post_excerpt' === ($result['writable_inputs']['description']['source'] ?? null), 'description must map to post_excerpt');
$assert('derived-read-only' === ($result['outputs']['canonical']['state'] ?? null), 'canonical must remain derived/read-only');
$assert(false === ($result['outputs']['canonical']['writable'] ?? true), 'canonical must not become directly writable');
$assert('derived-read-only' === ($result['outputs']['robots']['state'] ?? null), 'robots must remain derived/read-only');

$inspection = (array) ($result['evidence']['theme_contract_inspection'] ?? []);
$trace = (array) ($result['evidence']['data_source_trace'] ?? []);
$assert([] === (array) ($inspection['meta_keys'] ?? []), 'fixture must have no SEO-specific post-meta storage');
$assert(true === ($trace['classification']['native_post_content'] ?? false), 'native post sources must be proven');
$assert(true === ($trace['classification']['native_permalink'] ?? false), 'native permalink source must be proven');
$assert(false === ($trace['classification']['acf'] ?? true), 'ACF ownership must be absent');
$assert(false === ($trace['classification']['theme_mod'] ?? true), 'theme-mod ownership must be absent');
$assert(false === ($trace['classification']['custom_database'] ?? true), 'custom database ownership must be absent');

@unlink($themeRoot . '/inc/seo/Runtime.php');
@rmdir($themeRoot . '/inc/seo');
@rmdir($themeRoot . '/inc');
@rmdir($themeRoot);

echo "PASS: WordPress SM-3A.4 SEO provider readiness contract\n";
