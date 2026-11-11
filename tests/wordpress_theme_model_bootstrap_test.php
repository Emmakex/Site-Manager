<?php
declare(strict_types=1);

namespace SeoGeo\Theme\Strategic {
    /** Fake theme resolver only: always validates candidate through ThemeModelOverride. */
    final class CorporateHomeModelResolver
    {
        public function resolve(object $post): ?array
        {
            if (($GLOBALS['bootstrap_external_model'] ?? false) === true) {
                return ['model_id'=>'corporate-home-v1','source'=>'external','slots'=>['hero-lead'=>'External owner']];
            }
            return \SiteManager\Adapters\WordPress\ThemeModelOverride::provide(null, $post);
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    use SiteManager\Adapters\WordPress\ThemeModelBootstrap;
    use SiteManager\Adapters\WordPress\ThemeModelOverride;
    use SiteManager\Core\Model\OperationRequest;
    use SiteManager\Core\Planning\PlanFactory;

    $GLOBALS['bootstrap_page'] = (object)[
        'ID'=>23, 'post_type'=>'page', 'post_status'=>'publish',
        'post_modified_gmt'=>'2026-11-11 10:00:00',
        'post_content'=>'This is the original private Gutenberg page. Do not overwrite it.',
    ];
    $GLOBALS['bootstrap_meta'] = [];
    $GLOBALS['bootstrap_can_edit'] = true;
    $GLOBALS['bootstrap_external_model'] = false;
    $GLOBALS['bootstrap_home_id'] = 23;
    $GLOBALS['bootstrap_site_url'] = 'https://fixture.example.test/staging/';

    function get_option(string $name, mixed $default = false): mixed {
        return $name === 'page_on_front' ? $GLOBALS['bootstrap_home_id'] : $default;
    }
    function get_post(int $id): ?object {
        return $id === 23 ? $GLOBALS['bootstrap_page'] : null;
    }
    function home_url(string $path): string {
        return $GLOBALS['bootstrap_site_url'];
    }
    function get_post_meta(int $id, string $key, bool $single): mixed {
        return $GLOBALS['bootstrap_meta'][$id][$key] ?? '';
    }
    function update_post_meta(int $id, string $key, mixed $value): bool {
        $GLOBALS['bootstrap_meta'][$id][$key] = $value;
        return true;
    }
    function delete_post_meta(int $id, string $key): bool {
        unset($GLOBALS['bootstrap_meta'][$id][$key]);
        return true;
    }
    function current_user_can(string $capability, mixed ...$args): bool {
        return $capability === 'edit_post' && $args[0] === 23 && $GLOBALS['bootstrap_can_edit'];
    }
    function seo_geo_theme_preset_document(string $preset, string $name): array {
        if ($preset !== 'corporate' || $name !== 'content-map.json') {
            throw new \LogicException('Theme contract cannot be selected by a caller');
        }
        return [
            'native_content_models'=>['corporate-home-v1'=>['slots'=>[
                ['id'=>'hero-eyebrow','type'=>'text','required'=>true],
                ['id'=>'hero-lead','type'=>'text','required'=>true],
                ['id'=>'hero-primary-cta','type'=>'link','required'=>true],
                ['id'=>'hero-proof-heading','type'=>'text','required'=>false,'requires_verification'=>true],
            ]]],
        ];
    }
    function test_bootstrap(bool $value, string $message): void {
        if (! $value) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }
    function reject_bootstrap(callable $fn, string $message): void {
        try {
            $fn();
        } catch (\InvalidArgumentException | \LogicException) {
            return;
        }
        test_bootstrap(false, $message);
    }

    $service = new ThemeModelBootstrap();
    $start = $service->state();
    test_bootstrap($start['post_id'] === 23 && ! $start['already_resolved'], 'can adopt detected published WP front page');
    $defs = $start['definitions'];
    $fields = [
        'hero-eyebrow'=>'Digital strategy',
        'hero-lead'=>'Practical digital strategy with measurable outcomes',
        'hero-primary-cta'=>['label'=>'Contact us','url'=>'https://fixture.example.test/staging/contact/'],
    ];
    $valid = $service->validateFields($fields, $defs, $start['site_url']);
    test_bootstrap($valid === $fields, 'complete Theme-owned fields accepted');

    reject_bootstrap(fn()=> $service->validateFields(
        ['hero-lead'=>'Only one field'], $defs, $start['site_url']
    ), 'incomplete Theme required fields rejected');
    reject_bootstrap(fn()=> $service->validateFields(
        [...$fields,'hero-proof-heading'=>'Invented revenue growth claim'], $defs, $start['site_url']
    ), 'unverified proof blocked');
    reject_bootstrap(fn()=> $service->validateFields(
        array_replace($fields, ['hero-lead'=>'Sustituye por un texto de ejemplo']), $defs, $start['site_url']
    ), 'Theme filler copy must never be published');
    reject_bootstrap(fn()=> $service->validateFields(
        array_replace($fields, ['hero-lead'=>'<script>alert(1)</script>']), $defs, $start['site_url']
    ), 'markup blocked');
    reject_bootstrap(fn()=> $service->validateFields(
        array_replace($fields, ['hero-primary-cta'=>['label'=>'Outside','url'=>'https://attacker.test/contact/']]), $defs, $start['site_url']
    ), 'external links blocked');
    reject_bootstrap(fn()=> $service->validateFields(
        array_replace($fields, ['hero-primary-cta'=>['label'=>'Unexpected','url'=>'https://fixture.example.test/contact/']]), $defs, $start['site_url']
    ), 'links outside WordPress staging prefix blocked');

    $request = new OperationRequest(
        'content.write','theme.bootstrap',['slot_ids'=>array_keys($fields)],
        true,'editorial-review','explicit','Reviewed copy for a verified published home'
    );
    $plan = (new PlanFactory())->create(
        adapter:'seo-geo-theme-semantic-bootstrap',fingerprint:'discovery-synthetic',
        request:$request,
        changes:[
            'target'=>['post_id'=>23,'model_id'=>'corporate-home-v1'],
            'precondition_fingerprint'=>$start['fingerprint'],
            'after_model'=>[
                'model_id'=>'corporate-home-v1',
                'source'=>'site-manager-native-override',
                'slots'=>$valid,
            ],
            'fields'=>array_fill_keys(array_keys($valid), true),
        ],
        snapshot:[
            'before_fingerprint'=>$start['fingerprint'],
            'before_override'=>null,
            'post_content_hash'=>$start['post_content_hash'],
        ],
        reversible:true,
    );

    $GLOBALS['bootstrap_can_edit'] = false;
    reject_bootstrap(fn()=> $service->apply($plan), 'bounded administrator authorization required');
    $GLOBALS['bootstrap_can_edit'] = true;

    $apply = $service->apply($plan);
    test_bootstrap($apply->success, 'bootstrap applies only approved Site Manager model');
    test_bootstrap($service->verify($plan, $apply)->verified, 'Theme-owned resolver readback verifies content');
    test_bootstrap($GLOBALS['bootstrap_page']->post_content ===
        'This is the original private Gutenberg page. Do not overwrite it.', 'Gutenberg body unchanged');
    test_bootstrap($service->state()['model_source'] === 'site-manager-native-override', 'Site Manager owns only its overlay');

    $GLOBALS['bootstrap_meta'][23][ThemeModelOverride::META_KEY]['slots']['hero-lead'] = 'External concurrent edit';
    $stale = $service->rollback($plan, $apply);
    test_bootstrap(! $stale->success && $stale->status === 'stale-bootstrap-rollback', 'rollback rejects concurrent edits');

    $GLOBALS['bootstrap_meta'][23][ThemeModelOverride::META_KEY]['slots']['hero-lead'] = $fields['hero-lead'];
    $rollback = $service->rollback($plan, $apply);
    test_bootstrap($rollback->success, 'exact previous no-model state restored');
    test_bootstrap($service->state()['fingerprint'] === $start['fingerprint'], 'full bootstrap precondition fingerprint restored');
    test_bootstrap(! isset($GLOBALS['bootstrap_meta'][23][ThemeModelOverride::META_KEY]), 'only bootstrap meta removed');

    $GLOBALS['bootstrap_external_model'] = true;
    reject_bootstrap(fn()=> $service->plan($fields,'Forbidden external owner'),
        'bootstrap refuses an existing Theme provider');
    echo "PASS: Theme adoption validates complete authored copy, applies, verifies and rolls back without Gutenberg mutation\n";
}
