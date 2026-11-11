<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use RuntimeException;

/**
 * Inspect the active SEO/GEO Theme semantic model, never its raw content.
 *
 * Site Manager is the only governance plugin; this read uses the Theme's
 * existing public resolver and preset document, without SEO/GEO Manager.
 * Raw slot values, blocks and post meta never leave WordPress.
 */
final class ThemeModelInventory
{
    private const MODEL_ID = 'corporate-home-v1';
    private const MAX_SLOTS = 100;

    /** @return array<string,mixed> */
    public function homepage(): array
    {
        if (! function_exists('get_option') || ! function_exists('get_post')
            || ! function_exists('seo_geo_theme_preset_document')
            || ! class_exists('SeoGeo\\Theme\\Strategic\\CorporateHomeModelResolver')) {
            throw new RuntimeException('Active Theme does not expose the accepted semantic model.');
        }

        $postId = (int) get_option('page_on_front', 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (! is_object($post)
            || (int) ($post->ID ?? 0) !== $postId
            || (string) ($post->post_type ?? '') !== 'page'
            || (string) ($post->post_status ?? '') !== 'publish') {
            throw new RuntimeException('Published front page is not an accepted model target.');
        }

        $doc = seo_geo_theme_preset_document('corporate', 'content-map.json');
        $contract = is_array($doc) ? ($doc['native_content_models'][self::MODEL_ID] ?? null) : null;
        $definitions = is_array($contract) ? ($contract['slots'] ?? null) : null;
        if (! is_array($definitions) || count($definitions) < 1 || count($definitions) > self::MAX_SLOTS) {
            throw new RuntimeException('Corporate model contract is missing or malformed.');
        }

        $resolver = new \SeoGeo\Theme\Strategic\CorporateHomeModelResolver();
        $model = $resolver->resolve($post);
        if (! is_array($model) || ($model['model_id'] ?? null) !== self::MODEL_ID
            || ! is_array($model['slots'] ?? null)
            || count($model['slots']) > self::MAX_SLOTS) {
            throw new RuntimeException('Published homepage has no valid Theme semantic model.');
        }

        $source = $model['source'] ?? null;
        $source = in_array($source, ['hydrated-semantic-blocks', 'external'], true) ? $source : 'unknown';
        $slots = [];
        $seen = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                throw new RuntimeException('Invalid Theme slot definition.');
            }
            $id = $definition['id'] ?? null;
            $type = $definition['type'] ?? null;
            if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $id)
                || ! is_string($type) || ! in_array($type, ['text', 'link', 'list'], true)
                || isset($seen[$id])) {
                throw new RuntimeException('Ambiguous or unsupported Theme semantic slot.');
            }
            $seen[$id] = true;
            $value = $model['slots'][$id] ?? null;
            $present = match ($type) {
                'text' => is_string($value) && '' !== trim($value),
                'link' => is_array($value) && isset($value['label'], $value['url'])
                    && is_string($value['label']) && '' !== trim($value['label'])
                    && is_string($value['url']) && '' !== trim($value['url']),
                'list' => is_array($value) && array_is_list($value) && [] !== $value
                    && count($value) <= 40 && ! array_filter($value, static fn(mixed $v): bool =>
                        ! is_string($v) || '' === trim($v)),
            };
            if (! $present && true === ($definition['required'] ?? false)) {
                throw new RuntimeException('Required Theme semantic slot is absent.');
            }
            $slots[] = [
                'id'=>$id,
                'type'=>$type,
                'required'=>true === ($definition['required'] ?? false),
                'present'=>$present,
                'writable_via_site_manager'=>false,
            ];
        }
        if (array_diff(array_keys($model['slots']), array_keys($seen)) !== []) {
            throw new RuntimeException('Model contains unknown semantic slots.');
        }

        return [
            'source'=>'seo-geo-theme-corporate-model',
            'scope'=>'site.read',
            'observed_at'=>gmdate(DATE_W3C),
            'post_id'=>$postId,
            'post_type'=>'page',
            'post_status'=>'publish',
            'homepage'=>true,
            'model_id'=>self::MODEL_ID,
            'model_source'=>$source,
            'slot_count'=>count($slots),
            'slots'=>$slots,
            'public_values_included'=>false,
            'write_scope_verified'=>false,
            'editing_ready'=>false,
            'note'=>'Read-only structural inspection. No slot values are disclosed and no Theme edit is authorized.',
        ];
    }
}
