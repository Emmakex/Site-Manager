<?php
declare(strict_types=1);

namespace SeoGeo\Theme\Strategic {
    final class CorporateHomeModelResolver
    {
        public function resolve(object $post): ?array
        {
            return $GLOBALS['theme_model_fixture'] ?? null;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    use SiteManager\Adapters\WordPress\ThemeModelInventory;

    $GLOBALS['theme_model_fixture'] = [
        'model_id'=>'corporate-home-v1',
        'source'=>'hydrated-semantic-blocks',
        'slots'=>[
            'hero-eyebrow'=>'Consultoría digital',
            'hero-lead'=>'Private editorial wording must not leave the WordPress runtime',
            'hero-primary-cta'=>['label'=>'Hablemos','url'=>'https://emmake.com/nuevaweb/contacto/'],
            'hero-proof-list'=>['Estrategia', 'IA'],
        ],
    ];
    $GLOBALS['front_page_post'] = (object) [
        'ID'=>7, 'post_type'=>'page', 'post_status'=>'publish',
        'post_content'=>'<p>Private hydrated Gutenberg content must not leak</p>',
    ];
    $GLOBALS['theme_contract_override'] = null;
    $GLOBALS['site_url_override'] = null;

    function get_option(string $name, mixed $default = false): mixed {
        return $name === 'page_on_front' ? 7 : $default;
    }

    function get_post(int $id): ?object {
        return $id === 7 ? $GLOBALS['front_page_post'] : null;
    }

    function home_url(string $path): string {
        return $GLOBALS['site_url_override'] ?? 'https://emmake.com/nuevaweb/';
    }

    function seo_geo_theme_preset_document(string $preset, string $document): ?array {
        if ($preset !== 'corporate' || $document !== 'content-map.json') {
            throw new \RuntimeException('Unexpected Theme contract request');
        }
        return $GLOBALS['theme_contract_override'] ?? [
            'native_content_models'=>[
                'corporate-home-v1'=>[
                    'slots'=>[
                        ['id'=>'hero-eyebrow', 'type'=>'text', 'required'=>true],
                        ['id'=>'hero-lead', 'type'=>'text', 'required'=>true],
                        ['id'=>'hero-primary-cta', 'type'=>'link', 'required'=>true],
                        ['id'=>'hero-proof-list', 'type'=>'list', 'required'=>false],
                    ],
                ],
            ],
        ];
    }

    function assert_true(bool $ok, string $description): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: $description\n"); exit(1);
        }
    }

    function expect_rejection(string $description): void {
        try {
            (new ThemeModelInventory())->homepage();
        } catch (\RuntimeException) {
            return;
        }
        assert_true(false, $description);
    }

    $actual = (new ThemeModelInventory())->homepage();
    assert_true($actual['scope'] === 'site.read', 'only read scope');
    assert_true($actual['site_url'] === 'https://emmake.com/nuevaweb/', 'site identity');
    assert_true($actual['post_id'] === 7 && $actual['homepage'] === true, 'actual front page');
    assert_true($actual['slot_count'] === 4, 'all contract slots');
    assert_true($actual['editing_ready'] === false, 'no implied write authorization');
    assert_true($actual['write_scope_verified'] === false, 'scope must be checked separately');
    assert_true($actual['public_values_included'] === false, 'raw values never leave WordPress');
    assert_true($actual['slots'][2]['type'] === 'link', 'slot types from Theme');
    assert_true(! str_contains(json_encode($actual), 'Private editorial wording'), 'no content values leaked');
    assert_true(! str_contains(json_encode($actual), 'Gutenberg content'), 'no HTML leaked');
    assert_true(! str_contains(json_encode($actual), 'Hablemos'), 'no link labels leaked');

    $original = $GLOBALS['theme_model_fixture'];
    unset($GLOBALS['theme_model_fixture']['slots']['hero-eyebrow']);
    expect_rejection('required semantic slot missing');
    $GLOBALS['theme_model_fixture'] = $original;

    $GLOBALS['theme_model_fixture']['slots']['arbitrary-sensitive-field'] = 'secret';
    expect_rejection('unrecognized semantic slot');
    $GLOBALS['theme_model_fixture'] = $original;

    $GLOBALS['theme_model_fixture']['slots']['hero-primary-cta'] = ['url'=>'https://emmake.com/'];
    expect_rejection('required CTA label missing');
    $GLOBALS['theme_model_fixture'] = $original;

    $GLOBALS['front_page_post']->post_status = 'private';
    expect_rejection('private page must not expose Theme model');
    $GLOBALS['front_page_post']->post_status = 'publish';

    $GLOBALS['site_url_override'] = 'http://emmake.com/nuevaweb/';
    expect_rejection('HTTP site identity rejected');
    $GLOBALS['site_url_override'] = null;

    $GLOBALS['theme_contract_override'] = [
        'native_content_models'=>[
            'corporate-home-v1'=>['slots'=>[
                ['id'=>'hero-eyebrow','type'=>'text','required'=>true],
                ['id'=>'hero-eyebrow','type'=>'text','required'=>true],
            ]],
        ],
    ];
    expect_rejection('duplicate Theme slot identities');
    $GLOBALS['theme_contract_override'] = null;

    echo "PASS: SEO/GEO Theme semantic inspection via Site Manager without extra Manager plugin\n";
}
