<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

/** Bounded sample of images attached to public, non-password-protected native posts/pages. */
final class PublicImageInventory
{
    private const LIMIT = 30;

    public function page(int $page): array
    {
        if ($page < 1 || $page > 1000) { throw new \InvalidArgumentException('Invalid image inventory page.'); }
        $site = (string) home_url('/');
        $origin = wp_parse_url($site);
        if (! is_array($origin) || ($origin['scheme'] ?? '') !== 'https') {
            throw new \RuntimeException('HTTPS identity required.');
        }
        // Scan only one bounded attachment page; filtered-out items are not counted or exposed.
        $ids = get_posts([
            'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image',
            'posts_per_page'=>self::LIMIT + 1,'offset'=>($page - 1)*self::LIMIT,
            'orderby'=>'ID','order'=>'DESC','fields'=>'ids','no_found_rows'=>true,'suppress_filters'=>false,
        ]);
        if (! is_array($ids)) { throw new \RuntimeException('Attachment query unavailable.'); }
        $items = [];
        foreach (array_slice($ids, 0, self::LIMIT) as $id) {
            $id = (int) $id;
            $attachment = get_post($id);
            if (! $attachment || 'attachment' !== $attachment->post_type || 'inherit' !== $attachment->post_status
                || ! wp_attachment_is_image($id) || (int) $attachment->post_parent < 1) { continue; }
            $parent = get_post((int) $attachment->post_parent);
            if (! $parent || 'publish' !== $parent->post_status || '' !== (string) $parent->post_password
                || ! in_array($parent->post_type, ['post','page'], true)) { continue; }
            $url = wp_get_attachment_url($id);
            $parts = is_string($url) ? wp_parse_url($url) : false;
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($origin['host'] ?? ''))
                || ($parts['port'] ?? 443) !== ($origin['port'] ?? 443)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { continue; }
            $meta = wp_get_attachment_metadata($id);
            $items[] = [
                'attachment_id'=>$id,'parent_id'=>(int) $parent->ID,
                'title'=>wp_html_excerpt(wp_strip_all_tags((string) $attachment->post_title), 160, ''),
                'alt'=>wp_html_excerpt(wp_strip_all_tags((string) get_post_meta($id, '_wp_attachment_image_alt', true)), 200, ''),
                'mime'=>(string) $attachment->post_mime_type,
                'width'=>is_array($meta) ? max(0,(int) ($meta['width'] ?? 0)) : 0,
                'height'=>is_array($meta) ? max(0,(int) ($meta['height'] ?? 0)) : 0,
                'url'=>$url,
            ];
        }
        return ['source'=>'wordpress-native-public-image-sample','site_url'=>$site,'observed_at'=>gmdate(DATE_W3C),
            'page'=>$page,'page_size'=>self::LIMIT,'has_more'=>$page < 1000 && count($ids)>self::LIMIT,
            'items'=>$items,'sample_only'=>true,'private_values_included'=>false];
    }
}
