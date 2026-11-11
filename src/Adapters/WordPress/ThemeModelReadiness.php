<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

/**
 * Optional provider-specific readiness probe for the active SEO/GEO Theme.
 *
 * Only fixed status codes, booleans and bounded counts cross the remote
 * diagnostics boundary. WordPress post content and semantic values stay local.
 * An absent provider never affects generic Site Manager discovery/governance.
 */
final class ThemeModelReadiness
{
    private const MODEL_ID = 'corporate-home-v1';
    private const MAX_SLOTS = 100;

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $result = [
            'provider'=>'seo-geo-theme-corporate-home',
            'status'=>'blocked',
            'reason'=>'provider_unavailable',
            'front_page_id'=>null,
            'homepage_marker_present'=>false,
            'semantic_marker_present'=>false,
            'contract_slot_count'=>0,
            'missing_required_count'=>0,
            'model_source'=>'unavailable',
            'private_values_included'=>false,
            'write_authorized'=>false,
        ];

        if (! function_exists('seo_geo_theme_preset_document')
            || ! class_exists('SeoGeo\\Theme\\Strategic\\CorporateHomeModelResolver')
            || ! function_exists('get_option') || ! function_exists('get_post')) {
            return $result;
        }

        $postId = (int) get_option('page_on_front', 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (! is_object($post) || (int) ($post->ID ?? 0) !== $postId
            || (string) ($post->post_type ?? '') !== 'page'
            || (string) ($post->post_status ?? '') !== 'publish') {
            $result['reason'] = 'published_homepage_missing';
            return $result;
        }
        $result['front_page_id'] = $postId;

        $body = (string) ($post->post_content ?? '');
        $result['homepage_marker_present'] = str_contains($body, 'seo-geo-corporate-native-hero');
        $result['semantic_marker_present'] = str_contains($body, 'seo-geo-content-slot--');

        try {
            $document = seo_geo_theme_preset_document('corporate', 'content-map.json');
        } catch (\Throwable) {
            $result['reason'] = 'contract_unavailable';
            return $result;
        }
        $contract = is_array($document) ? ($document['native_content_models'][self::MODEL_ID] ?? null) : null;
        $definitions = is_array($contract) ? ($contract['slots'] ?? null) : null;
        if (! is_array($definitions) || ! array_is_list($definitions)
            || count($definitions) < 1 || count($definitions) > self::MAX_SLOTS) {
            $result['reason'] = 'contract_unavailable';
            return $result;
        }

        $required = [];
        $known = [];
        foreach ($definitions as $definition) {
            $id = is_array($definition) ? ($definition['id'] ?? null) : null;
            $type = is_array($definition) ? ($definition['type'] ?? null) : null;
            if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $id)
                || ! in_array($type, ['text', 'link', 'list'], true)
                || isset($known[$id])) {
                $result['reason'] = 'contract_unavailable';
                return $result;
            }
            $known[$id] = true;
            if (true === ($definition['required'] ?? false)) {
                $required[$id] = $type;
            }
        }
        $result['contract_slot_count'] = count($definitions);

        // External and Site Manager-owned semantic models may not need legacy Gutenberg markers.
        try {
            $model = (new \SeoGeo\Theme\Strategic\CorporateHomeModelResolver())->resolve($post);
        } catch (\Throwable) {
            $result['reason'] = 'resolver_failed';
            return $result;
        }
        if (! is_array($model) || ($model['model_id'] ?? null) !== self::MODEL_ID
            || ! is_array($model['slots'] ?? null)
            || count($model['slots']) > self::MAX_SLOTS) {
            $result['reason'] = ! $result['homepage_marker_present']
                ? 'homepage_marker_missing' : 'resolver_rejected_model';
            return $result;
        }

        $source = $model['source'] ?? null;
        $result['model_source'] = in_array(
            $source, ['hydrated-semantic-blocks', 'site-manager-native-override', 'external'], true
        ) ? $source : 'unknown';

        $missing = 0;
        foreach ($required as $id => $type) {
            $value = $model['slots'][$id] ?? null;
            $present = match ($type) {
                'text' => is_string($value) && '' !== trim($value),
                'link' => is_array($value) && is_string($value['label'] ?? null)
                    && '' !== trim($value['label'])
                    && is_string($value['url'] ?? null) && '' !== trim($value['url']),
                'list' => is_array($value) && array_is_list($value) && $value !== [],
            };
            if (! $present) {
                ++$missing;
            }
        }
        $result['missing_required_count'] = $missing;
        if ($missing > 0) {
            $result['reason'] = 'required_slots_incomplete';
            return $result;
        }

        try {
            (new ThemeModelInventory())->homepage();
        } catch (\Throwable) {
            $result['reason'] = 'inventory_contract_rejected';
            return $result;
        }
        $result['status'] = 'ready';
        $result['reason'] = 'ready';
        return $result;
    }
}
