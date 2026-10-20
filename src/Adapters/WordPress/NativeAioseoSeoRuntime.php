<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class NativeAioseoSeoRuntime implements AioseoSeoRuntime
{
    private const KEYS = ['title','description','canonical_url'];

    public function available(): bool
    {
        return function_exists('aioseo')
            && function_exists('get_post')
            && function_exists('current_user_can')
            && function_exists('rest_get_route_for_post')
            && function_exists('rest_do_request');
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
        $route = rest_get_route_for_post($post);
        if (! is_string($route) || '' === $route) {
            return null;
        }
        $response = rest_do_request(new \WP_REST_Request('GET', $route));
        if ($response->is_error()) {
            return null;
        }
        $data = $response->get_data();
        if (! is_array($data) || ! array_key_exists('aioseo_meta_data', $data)) {
            return null;
        }
        $meta = is_array($data['aioseo_meta_data']) ? $data['aioseo_meta_data'] : [];
        $row = [
            'ID'=>(int) $post->ID,
            'post_type'=>(string) $post->post_type,
            'post_modified_gmt'=>(string) $post->post_modified_gmt,
        ];
        foreach (self::KEYS as $key) {
            $row[$key] = (string) ($meta[$key] ?? '');
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
            return ['success'=>false,'post_id'=>$postId,'error'=>'aioseo-rest-api-unavailable'];
        }
        foreach ($fields as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                return ['success'=>false,'post_id'=>$postId,'error'=>'unsupported-aioseo-key:' . $key];
            }
        }
        $post = get_post($postId);
        if (! $post) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'target-missing'];
        }
        $route = rest_get_route_for_post($post);
        if (! is_string($route) || '' === $route) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'rest-route-unavailable'];
        }

        $request = new \WP_REST_Request('POST', $route);
        $request->set_param('aioseo_meta_data', $fields);
        $response = rest_do_request($request);
        if ($response->is_error()) {
            $error = $response->as_error();
            return ['success'=>false,'post_id'=>$postId,'error'=>$error->get_error_message()];
        }

        $current = $this->read($postId);
        if (null === $current) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'post-update-read-failed'];
        }
        foreach ($fields as $key => $value) {
            if ((string) ($current[$key] ?? '') !== (string) $value) {
                return ['success'=>false,'post_id'=>$postId,'error'=>'aioseo-value-mismatch:' . $key];
            }
        }
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}
