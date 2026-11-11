<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

/**
 * SEO/GEO Theme remains the only public renderer. Site Manager may supply
 * a privately stored, fully validated semantic model via the Theme's
 * native extension filter; it never writes Gutenberg layout blocks.
 */
final class ThemeModelOverride
{
    public const META_KEY = '_site_manager_corporate_home_model_v1';

    public static function boot(): void
    {
        add_filter('seo_geo_theme_corporate_home_model', [self::class, 'provide'], 15, 2);
    }

    public static function provide(mixed $previous, mixed $post): mixed
    {
        // Never replace another provider's already accepted model.
        if (null !== $previous) {
            return $previous;
        }
        if (! is_object($post)
            || (int) ($post->ID ?? 0) < 1
            || (int) ($post->ID ?? 0) !== (int) get_option('page_on_front', 0)
            || (string) ($post->post_type ?? '') !== 'page'
            || (string) ($post->post_status ?? '') !== 'publish') {
            return null;
        }

        $stored = get_post_meta((int) $post->ID, self::META_KEY, true);
        if (! is_array($stored)
            || ($stored['model_id'] ?? null) !== 'corporate-home-v1'
            || ! is_array($stored['slots'] ?? null)
            || ($stored['source'] ?? null) !== 'site-manager-native-override') {
            return null;
        }

        // The Theme's own resolver still validates required slots.
        return $stored;
    }
}
