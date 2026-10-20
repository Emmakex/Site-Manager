<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class NativeNavigationRuntime implements NavigationRuntime
{
    public const CLASSIC = 'wordpress-classic-menu';
    public const BLOCK = 'wordpress-block-navigation';

    public function read(string $provider, int $targetId): ?array
    {
        return match ($provider) {
            self::CLASSIC => $this->readClassic($targetId),
            self::BLOCK => $this->readBlock($targetId),
            default => null,
        };
    }

    public function canEdit(string $provider, int $targetId): bool
    {
        return match ($provider) {
            self::CLASSIC => current_user_can('edit_theme_options'),
            self::BLOCK => current_user_can('edit_post', $targetId) || current_user_can('edit_theme_options'),
            default => false,
        };
    }

    public function sanitize(string $provider, string $field, mixed $value): mixed
    {
        if (self::CLASSIC === $provider) {
            return match ($field) {
                'title', 'description', 'attr_title', 'xfn' => sanitize_text_field(is_scalar($value) ? (string) $value : ''),
                'url' => esc_url_raw(is_scalar($value) ? (string) $value : ''),
                'target' => '_blank' === (string) $value ? '_blank' : '',
                'classes' => array_values(array_filter(array_map('sanitize_html_class', is_array($value) ? $value : (preg_split('/\s+/', (string) $value) ?: [])))),
                'menu_item_parent', 'menu_order' => max(0, (int) $value),
                'status' => in_array((string) $value, ['publish','draft'], true) ? (string) $value : 'publish',
                default => '',
            };
        }

        if (self::BLOCK === $provider) {
            return match ($field) {
                'title' => sanitize_text_field(is_scalar($value) ? (string) $value : ''),
                'content' => wp_kses_post(is_scalar($value) ? (string) $value : ''),
                'status' => in_array((string) $value, ['publish','draft'], true) ? (string) $value : 'publish',
                default => '',
            };
        }

        return '';
    }

    public function update(string $provider, int $targetId, array $fields): array
    {
        return match ($provider) {
            self::CLASSIC => $this->updateClassic($targetId, $fields),
            self::BLOCK => $this->updateBlock($targetId, $fields),
            default => ['success'=>false,'target_id'=>$targetId,'error'=>'Unsupported navigation provider.'],
        };
    }

    /** @return array<string,mixed>|null */
    private function readClassic(int $targetId): ?array
    {
        $post = get_post($targetId);
        if (! $post || 'nav_menu_item' !== (string) $post->post_type) {
            return null;
        }
        $item = wp_setup_nav_menu_item($post);
        if (! $item) {
            return null;
        }
        $terms = wp_get_object_terms($targetId, 'nav_menu', ['fields'=>'ids']);
        if (is_wp_error($terms) || ! $terms) {
            return null;
        }

        return [
            'provider'=>self::CLASSIC,
            'ID'=>(int) $item->ID,
            'menu_id'=>(int) reset($terms),
            'type'=>(string) ($item->type ?? ''),
            'object'=>(string) ($item->object ?? ''),
            'object_id'=>(int) ($item->object_id ?? 0),
            'title'=>(string) ($item->title ?? ''),
            'url'=>(string) ($item->url ?? ''),
            'description'=>(string) ($item->description ?? ''),
            'attr_title'=>(string) ($item->attr_title ?? ''),
            'target'=>(string) ($item->target ?? ''),
            'classes'=>array_values(array_filter(array_map('strval', (array) ($item->classes ?? [])))),
            'xfn'=>(string) ($item->xfn ?? ''),
            'menu_item_parent'=>(int) ($item->menu_item_parent ?? 0),
            'menu_order'=>(int) ($item->menu_order ?? 0),
            'status'=>(string) ($item->post_status ?? 'publish'),
            'modified_gmt'=>(string) ($item->post_modified_gmt ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    private function readBlock(int $targetId): ?array
    {
        $post = get_post($targetId);
        if (! $post || 'wp_navigation' !== (string) $post->post_type) {
            return null;
        }
        return [
            'provider'=>self::BLOCK,
            'ID'=>(int) $post->ID,
            'title'=>(string) $post->post_title,
            'content'=>(string) $post->post_content,
            'status'=>(string) $post->post_status,
            'modified_gmt'=>(string) $post->post_modified_gmt,
        ];
    }

    /** @param array<string,mixed> $fields */
    private function updateClassic(int $targetId, array $fields): array
    {
        $current = $this->readClassic($targetId);
        if (null === $current) {
            return ['success'=>false,'target_id'=>$targetId,'error'=>'Classic navigation item not found.'];
        }
        $merged = array_replace($current, $fields);
        $args = [
            'menu-item-db-id'=>$targetId,
            'menu-item-object-id'=>(int) $merged['object_id'],
            'menu-item-object'=>(string) $merged['object'],
            'menu-item-parent-id'=>(int) $merged['menu_item_parent'],
            'menu-item-position'=>(int) $merged['menu_order'],
            'menu-item-type'=>(string) $merged['type'],
            'menu-item-title'=>(string) $merged['title'],
            'menu-item-url'=>(string) $merged['url'],
            'menu-item-description'=>(string) $merged['description'],
            'menu-item-attr-title'=>(string) $merged['attr_title'],
            'menu-item-target'=>(string) $merged['target'],
            'menu-item-classes'=>implode(' ', (array) $merged['classes']),
            'menu-item-xfn'=>(string) $merged['xfn'],
            'menu-item-status'=>(string) $merged['status'],
        ];
        $result = wp_update_nav_menu_item((int) $current['menu_id'], $targetId, $args);
        if (is_wp_error($result)) {
            return ['success'=>false,'target_id'=>$targetId,'error'=>$result->get_error_message()];
        }
        return ['success'=>true,'target_id'=>(int) $result,'error'=>''];
    }

    /** @param array<string,mixed> $fields */
    private function updateBlock(int $targetId, array $fields): array
    {
        $payload = ['ID'=>$targetId];
        if (array_key_exists('title', $fields)) { $payload['post_title'] = (string) $fields['title']; }
        if (array_key_exists('content', $fields)) { $payload['post_content'] = (string) $fields['content']; }
        if (array_key_exists('status', $fields)) { $payload['post_status'] = (string) $fields['status']; }
        $result = wp_update_post($payload, true);
        if (is_wp_error($result)) {
            return ['success'=>false,'target_id'=>$targetId,'error'=>$result->get_error_message()];
        }
        return ['success'=>true,'target_id'=>(int) $result,'error'=>''];
    }
}
