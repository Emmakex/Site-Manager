<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class NativeRankMathSeoRuntime implements RankMathSeoRuntime
{
    private const KEYS = [
        'rank_math_title',
        'rank_math_description',
        'rank_math_canonical_url',
    ];

    public function available(): bool
    {
        return class_exists('RankMath')
            && function_exists('get_post')
            && function_exists('get_post_meta')
            && function_exists('update_post_meta')
            && function_exists('current_user_can');
    }

    public function read(int $postId): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $post = get_post($postId);
        if (! $post) {
            return null;
        }
        $row = [
            'ID'=>(int) $post->ID,
            'post_type'=>(string) $post->post_type,
            'post_modified_gmt'=>(string) $post->post_modified_gmt,
        ];
        foreach (self::KEYS as $key) {
            $row[$key] = (string) get_post_meta($postId, $key, true);
        }
        return $row;
    }

    public function canEdit(int $postId): bool
    {
        return $this->available() && current_user_can('edit_post', $postId);
    }

    public function update(int $postId, array $fields): array
    {
        if (! $this->available()) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'rank-math-api-unavailable'];
        }
        foreach ($fields as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                return ['success'=>false,'post_id'=>$postId,'error'=>'unsupported-rank-math-key:' . $key];
            }
            update_post_meta($postId, $key, $value);
        }
        $current = $this->read($postId);
        if (null === $current) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'post-update-read-failed'];
        }
        foreach ($fields as $key => $value) {
            if ((string) ($current[$key] ?? '') !== (string) $value) {
                return ['success'=>false,'post_id'=>$postId,'error'=>'rank-math-value-mismatch:' . $key];
            }
        }
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}
