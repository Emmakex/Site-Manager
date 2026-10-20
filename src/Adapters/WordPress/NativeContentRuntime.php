<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class NativeContentRuntime implements ContentRuntime
{
    public function read(int $postId): ?array
    {
        $post = get_post($postId);
        if (! $post) {
            return null;
        }

        return [
            'ID'=>(int) $post->ID,
            'post_type'=>(string) $post->post_type,
            'post_title'=>(string) $post->post_title,
            'post_name'=>(string) $post->post_name,
            'post_content'=>(string) $post->post_content,
            'post_excerpt'=>(string) $post->post_excerpt,
            'post_status'=>(string) $post->post_status,
            'post_modified_gmt'=>(string) $post->post_modified_gmt,
        ];
    }

    public function canEdit(int $postId): bool
    {
        return current_user_can('edit_post', $postId);
    }

    public function sanitize(string $field, mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        return match ($field) {
            'post_title' => sanitize_text_field($value),
            'post_name' => sanitize_title($value),
            'post_content', 'post_excerpt' => wp_kses_post($value),
            'post_status' => sanitize_key($value),
            default => '',
        };
    }

    public function update(int $postId, array $fields): array
    {
        $payload = ['ID'=>$postId] + $fields;
        $result = wp_update_post($payload, true);
        if (is_wp_error($result)) {
            return ['success'=>false,'post_id'=>$postId,'error'=>$result->get_error_message()];
        }

        return ['success'=>true,'post_id'=>(int) $result,'error'=>''];
    }
}
