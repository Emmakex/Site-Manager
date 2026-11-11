<?php
declare(strict_types=1);

namespace SeoGeo\Theme\Strategic {
    final class CorporateHomeModelResolver
    {
        public function resolve(object $post): ?array
        {
            $override = \SiteManager\Adapters\WordPress\ThemeModelOverride::provide(null, $post);
            return is_array($override) ? $override : $GLOBALS['test_corporate_home_original'];
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    use SiteManager\Adapters\WordPress\ThemeTextGovernance;
    use SiteManager\Adapters\WordPress\ThemeModelOverride;
    use SiteManager\Core\Model\OperationRequest;
    use SiteManager\Core\Planning\PlanFactory;

    $GLOBALS['test_theme_meta'] = [];
    $GLOBALS['test_corporate_home_original'] = [
        'model_id'=>'corporate-home-v1',
        'source'=>'hydrated-semantic-blocks',
        'slots'=>[
            'hero-eyebrow'=>'Consultoría',
            'hero-lead'=>'Contenido original',
            'hero-primary-cta'=>['label'=>'Contacto','url'=>'/contacto/'],
            'hero-proof-heading'=>'Afirmación sujeta a verificación',
        ],
    ];
    $GLOBALS['test_wp_home'] = (object) [
        'ID'=>7,'post_type'=>'page','post_status'=>'publish',
        'post_modified_gmt'=>'2026-11-11 10:00:00',
    ];
    $GLOBALS['test_can_edit'] = true;

    function get_option(string $name, mixed $default = false): mixed {
        return 'page_on_front' === $name ? 7 : $default;
    }
    function get_post(int $id): ?object {
        return 7 === $id ? $GLOBALS['test_wp_home'] : null;
    }
    function home_url(string $path): string {
        return 'https://emmake.com/nuevaweb/';
    }
    function get_post_meta(int $postId, string $key, bool $single): mixed {
        return $GLOBALS['test_theme_meta'][$postId][$key] ?? '';
    }
    function update_post_meta(int $postId, string $key, mixed $value): bool {
        $GLOBALS['test_theme_meta'][$postId][$key] = $value;
        return true;
    }
    function delete_post_meta(int $postId, string $key): bool {
        unset($GLOBALS['test_theme_meta'][$postId][$key]);
        return true;
    }
    function current_user_can(string $capability, mixed ...$args): bool {
        return $capability === 'edit_post' && ($args[0] ?? null) === 7
            && $GLOBALS['test_can_edit'];
    }
    function seo_geo_theme_preset_document(string $preset, string $document): array {
        if ($preset !== 'corporate' || $document !== 'content-map.json') {
            throw new \RuntimeException('Unexpected contract');
        }
        return ['native_content_models'=>[
            'corporate-home-v1'=>['slots'=>[
                ['id'=>'hero-eyebrow','type'=>'text','required'=>true],
                ['id'=>'hero-lead','type'=>'text','required'=>true],
                ['id'=>'hero-primary-cta','type'=>'link','required'=>true],
                ['id'=>'hero-proof-heading','type'=>'text','required'=>false,'requires_verification'=>true],
            ]],
        ]];
    }
    function check(bool $ok, string $message): void {
        if (! $ok) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
    }

    $service = new ThemeTextGovernance();
    $before = $service->state();
    check($before['source'] === 'hydrated-semantic-blocks', 'Theme is original model');
    $afterSlots = array_replace($before['slots'], ['hero-lead'=>'Texto aprobado nuevo']);
    $request = new OperationRequest(
        'content.write', 'theme.update', ['fields'=>['hero-lead'=>'Texto aprobado nuevo']],
        true, 'editorial-review', 'explicit', 'Test accepted Theme slot'
    );
    $plan = (new PlanFactory())->create(
        adapter:'seo-geo-theme-semantic',
        fingerprint:'synthetic-site-fingerprint',
        request:$request,
        changes:[
            'target'=>['post_id'=>7,'model_id'=>'corporate-home-v1'],
            'fields'=>['hero-lead'=>'Texto aprobado nuevo'],
            'precondition_fingerprint'=>$before['fingerprint'],
            'after_model'=>[
                'model_id'=>'corporate-home-v1',
                'source'=>'site-manager-native-override',
                'slots'=>$afterSlots,
            ],
        ],
        snapshot:[
            'previous_override'=>null,
            'original_model'=>$before['slots'],
            'original_fingerprint'=>$before['fingerprint'],
        ],
        reversible:true,
    );
    $apply = $service->apply($plan);
    check($apply->success, 'accepted Theme change applied');
    check($service->verify($plan, $apply)->verified, 'Theme resolver verified applied value');
    $current = $service->state();
    check($current['slots']['hero-lead'] === 'Texto aprobado nuevo', 'new semantic copy visible to Theme');
    check($current['slots']['hero-primary-cta'] === ['label'=>'Contacto','url'=>'/contacto/'], 'untouched CTA preserved');
    check($current['source'] === 'site-manager-native-override', 'Theme switched to native Site Manager model');

    $GLOBALS['test_theme_meta'][7][ThemeModelOverride::META_KEY]['slots']['hero-lead'] = 'Changed concurrently';
    $stale = $service->rollback($plan, $apply);
    check(! $stale->success && $stale->status === 'stale-theme-rollback', 'rollback is stale-safe');

    $GLOBALS['test_theme_meta'][7][ThemeModelOverride::META_KEY]['slots']['hero-lead'] = 'Texto aprobado nuevo';
    $restored = $service->rollback($plan, $apply);
    check($restored->success, 'rollback succeeded');
    check($service->state()['fingerprint'] === $before['fingerprint'], 'exact original model restored');
    check(! isset($GLOBALS['test_theme_meta'][7][ThemeModelOverride::META_KEY]), 'saved override removed');

    $GLOBALS['test_can_edit'] = false;
    try {
        $service->apply($plan);
        check(false, 'unauthorized apply must refuse');
    } catch (\LogicException) {
        // expected
    }
    $GLOBALS['test_can_edit'] = true;

    $GLOBALS['test_wp_home']->post_status = 'private';
    try {
        $service->state();
        check(false, 'private homepage must refuse');
    } catch (\LogicException) {
        // expected
    }

    echo "PASS: Theme native semantic Apply / Verify / stale rollback / exact restore\n";
}
