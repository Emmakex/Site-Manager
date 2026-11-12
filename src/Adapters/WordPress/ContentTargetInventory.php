<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use RuntimeException;

/**
 * A bounded, read-only index of PUBLISHED native WordPress pages/posts.
 *
 * The site.read scope deliberately never exposes drafts, revisions, bodies,
 * excerpts, private posts, arbitrary post meta, or credentials through GitHub.
 * Future private value-level inspection requires its own stronger contract.
 */
final class ContentTargetInventory
{
    private const MAX_PAGES = 80;
    private const MAX_POSTS = 40;

    /** @return array<string,mixed> */
    public function publishedTargets(): array
    {
        if (! function_exists('get_posts') || ! function_exists('get_post')) {
            throw new RuntimeException('WordPress native content APIs unavailable.');
        }

        $siteUrl = function_exists('home_url') ? (string) home_url('/') : '';
        if (! str_starts_with($siteUrl, 'https://')) {
            throw new RuntimeException('Site Manager requires HTTPS site identity.');
        }

        $homepageId = (int) get_option('page_on_front', 0);
        $pages = $this->listPublished('page', self::MAX_PAGES, $siteUrl, $homepageId);
        $posts = $this->listPublished('post', self::MAX_POSTS, $siteUrl, $homepageId);
        $pageTotal = $this->countPublished('page');
        $postTotal = $this->countPublished('post');
        if ($pageTotal < count($pages['items']) || $postTotal < count($posts['items'])) {
            throw new RuntimeException('Published count is inconsistent with bounded target list.');
        }

        return [
            'source'=>'wordpress-native-published-content',
            'observed_at'=>gmdate(DATE_W3C),
            'site_url'=>$siteUrl,
            'homepage_post_id'=>$homepageId > 0 ? $homepageId : null,
            'scope'=>'site.read',
            'published_only'=>true,
            'page_limit'=>self::MAX_PAGES,
            'post_limit'=>self::MAX_POSTS,
            // Complete published counts, NOT the size of the bounded first page.
            // This never includes draft/private/pending/trash counts or bodies.
            'published_page_total'=>$pageTotal,
            'published_post_total'=>$postTotal,
            'pages'=>$pages['items'],
            'posts'=>$posts['items'],
            'pages_truncated'=>$pageTotal > self::MAX_PAGES || $pages['truncated'],
            'posts_truncated'=>$postTotal > self::MAX_POSTS || $posts['truncated'],
            'private_values_included'=>false,
        ];
    }

    private function countPublished(string $type): int
    {
        if (! function_exists('wp_count_posts')) {
            throw new RuntimeException('WordPress published count API unavailable.');
        }
        $counts = wp_count_posts($type);
        $value = is_object($counts) ? ($counts->publish ?? null) : null;
        if (! is_numeric($value) || (string) (int) $value !== (string) $value
            || (int) $value < 0 || (int) $value > 10000000) {
            throw new RuntimeException('WordPress returned invalid published count.');
        }
        return (int) $value;
    }

    /**
     * @return array{items:list<array<string,mixed>>,truncated:bool}
     */
    private function listPublished(string $type, int $limit, string $siteUrl, int $homepageId): array
    {
        $posts = get_posts([
            'post_type'=>$type,
            'post_status'=>'publish',
            'posts_per_page'=>$limit + 1,
            'orderby'=>$type === 'page'
                ? ['menu_order'=>'ASC', 'ID'=>'ASC']
                : ['modified'=>'DESC', 'ID'=>'DESC'],
            'suppress_filters'=>false,
            'no_found_rows'=>true,
            'fields'=>'ids',
        ]);
        if (! is_array($posts)) {
            throw new RuntimeException('WordPress returned invalid published-target inventory.');
        }

        $truncated = count($posts) > $limit;
        $items = [];
        foreach (array_slice($posts, 0, $limit) as $id) {
            // Reject unexpected object or selector-shaped query results.
            if (! is_int($id) && ! ctype_digit((string) $id)) {
                throw new RuntimeException('Invalid WordPress target ID.');
            }
            $postId = (int) $id;
            $post = get_post($postId);
            if (! is_object($post)) {
                throw new RuntimeException('WordPress published target disappeared during inventory.');
            }
            if ((string) ($post->post_type ?? '') !== $type
                || (string) ($post->post_status ?? '') !== 'publish') {
                // Do not leak private/stale content even when the query shifts.
                throw new RuntimeException('WordPress target was no longer public.');
            }

            $permalink = function_exists('get_permalink') ? get_permalink($postId) : false;
            if (! is_string($permalink) || ! str_starts_with($permalink, $siteUrl)) {
                throw new RuntimeException('WordPress target permalink escaped site origin/path.');
            }
            $title = (string) ($post->post_title ?? '');
            $title = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($title, true) : strip_tags($title);
            $items[] = [
                'post_id'=>$postId,
                'post_type'=>$type,
                'status'=>'publish',
                'title'=>substr($title, 0, 160),
                'slug'=>substr((string) ($post->post_name ?? ''), 0, 200),
                'permalink'=>$permalink,
                'parent_id'=>max(0, (int) ($post->post_parent ?? 0)),
                'homepage'=>$type === 'page' && $homepageId === $postId,
                'modified_gmt'=>substr((string) ($post->post_modified_gmt ?? ''), 0, 32),
            ];
        }
        return ['items'=>$items,'truncated'=>$truncated];
    }
}
