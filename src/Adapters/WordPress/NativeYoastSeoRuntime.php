<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class NativeYoastSeoRuntime implements YoastSeoRuntime
{
    private const KEYS = ['title','metadesc','canonical','meta-robots-noindex'];

    public function available(): bool
    {
        return class_exists('WPSEO_Meta')
            && method_exists('WPSEO_Meta', 'get_value')
            && method_exists('WPSEO_Meta', 'set_value')
            && function_exists('get_post')
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
            $row[$key] = (string) \WPSEO_Meta::get_value($key, $postId);
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
            return ['success'=>false,'post_id'=>$postId,'error'=>'yoast-api-unavailable'];
        }
        foreach ($fields as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                return ['success'=>false,'post_id'=>$postId,'error'=>'unsupported-yoast-key:' . $key];
            }
            \WPSEO_Meta::set_value($key, $value, $postId);
        }

        $current = $this->read($postId);
        if (null === $current) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'post-update-read-failed'];
        }
        foreach ($fields as $key => $value) {
            if ((string) ($current[$key] ?? '') !== (string) $value) {
                return ['success'=>false,'post_id'=>$postId,'error'=>'yoast-value-mismatch:' . $key];
            }
        }
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}
