<?php
declare(strict_types=1);

namespace SeoGeo\Theme\Strategic {
    final class CorporateHomeModelResolver
    {
        public function resolve(object $post): ?array
        {
            if ($GLOBALS['readiness_resolver_throws'] ?? false) {
                throw new \RuntimeException('Secret resolver diagnostic must never be exposed');
            }
            return $GLOBALS['readiness_model'];
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    use SiteManager\Adapters\WordPress\ThemeModelReadiness;

    $GLOBALS['readiness_post'] = (object)[
        'ID'=>7, 'post_type'=>'page', 'post_status'=>'publish',
        'post_content'=>'<!-- wp:group {"className":"seo-geo-corporate-native-hero seo-geo-content-slot--hero-lead"} -->Private sensitive editorial text<!-- /wp:group -->',
    ];
    $GLOBALS['readiness_model'] = [
        'model_id'=>'corporate-home-v1',
        'source'=>'hydrated-semantic-blocks',
        'slots'=>[
            'hero-lead'=>'Secret customer copy',
            'hero-primary-cta'=>['label'=>'Private CTA label', 'url'=>'https://emmake.com/nuevaweb/contacto/'],
        ],
    ];
    $GLOBALS['readiness_contract'] = [
        'native_content_models'=>[
            'corporate-home-v1'=>['slots'=>[
                ['id'=>'hero-lead','type'=>'text','required'=>true],
                ['id'=>'hero-primary-cta','type'=>'link','required'=>true],
            ]],
        ],
    ];
    $GLOBALS['readiness_resolver_throws'] = false;
    $GLOBALS['readiness_home_id'] = 7;

    function get_option(string $key, mixed $default = false): mixed
    {
        return $key === 'page_on_front' ? $GLOBALS['readiness_home_id'] : $default;
    }
    function get_post(int $id): ?object
    {
        return $id === 7 ? $GLOBALS['readiness_post'] : null;
    }
    function home_url(string $path): string
    {
        return 'https://emmake.com/nuevaweb/';
    }
    function seo_geo_theme_preset_document(string $preset, string $file): ?array
    {
        return $GLOBALS['readiness_contract'];
    }
    function assert_readiness(string $reason, string $status = 'blocked'): void
    {
        $result = (new ThemeModelReadiness())->inspect();
        if ($result['reason'] !== $reason || $result['status'] !== $status) {
            fwrite(STDERR, "FAIL: expected {$status}/{$reason}, got " . json_encode($result) . "\n");
            exit(1);
        }
        if ($result['private_values_included'] !== false || $result['write_authorized'] !== false) {
            exit("FAIL: inspection must not confer write access or promise public content\n");
        }
        $text = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['Secret customer copy', 'Private CTA label', 'Private sensitive editorial text',
                  'Secret resolver diagnostic', 'contacto/', 'post_content', 'post_meta'] as $private) {
            if (str_contains($text, $private)) {
                exit("FAIL: readiness result leaked WordPress/private values\n");
            }
        }
        $expected = [
            'provider', 'status', 'reason', 'front_page_id',
            'homepage_marker_present', 'semantic_marker_present',
            'contract_slot_count', 'missing_required_count', 'model_source',
            'private_values_included', 'write_authorized',
        ];
        sort($expected);
        $actual = array_keys($result);
        sort($actual);
        if ($expected !== $actual) {
            exit("FAIL: readiness result changed bounded contract\n");
        }
    }

    assert_readiness('ready', 'ready');
    $originalModel = $GLOBALS['readiness_model'];
    $GLOBALS['readiness_post']->post_content = 'Private editorial content without native markers';
    $GLOBALS['readiness_model'] = null;
    assert_readiness('homepage_marker_missing');

    // A provider-supplied semantic model does not require legacy Gutenberg markup.
    $GLOBALS['readiness_model'] = array_replace($originalModel, ['source'=>'site-manager-native-override']);
    assert_readiness('ready', 'ready');
    $GLOBALS['readiness_post']->post_content = '<div class="seo-geo-corporate-native-hero seo-geo-content-slot--hero-lead">Sensitive</div>';
    $GLOBALS['readiness_model'] = null;
    assert_readiness('resolver_rejected_model');

    $GLOBALS['readiness_model'] = [
        'model_id'=>'corporate-home-v1', 'source'=>'hydrated-semantic-blocks',
        'slots'=>['hero-lead'=>'Secret customer copy'],
    ];
    assert_readiness('required_slots_incomplete');

    $GLOBALS['readiness_resolver_throws'] = true;
    assert_readiness('resolver_failed');
    $GLOBALS['readiness_resolver_throws'] = false;

    $GLOBALS['readiness_contract'] = null;
    assert_readiness('contract_unavailable');

    $GLOBALS['readiness_home_id'] = 0;
    assert_readiness('published_homepage_missing');

    echo "PASS: optional Theme readiness is reason-coded, read-only and excludes private values\n";
}
