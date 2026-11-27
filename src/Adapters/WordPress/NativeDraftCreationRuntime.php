<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use SiteManager\Core\Support\CanonicalJson;

final class NativeDraftCreationRuntime implements DraftCreationRuntime
{
    private const MARKER = '_site_manager_creation_plan';

    public function canCreate(string $type): bool
    {
        if (! in_array($type, ['post','page'], true)) { return false; }
        $object = get_post_type_object($type);
        return null !== $object && isset($object->cap->create_posts)
            && current_user_can($object->cap->create_posts);
    }

    public function sanitize(string $field, mixed $value): string
    {
        return (new NativeContentRuntime())->sanitize($field, $value);
    }

    public function slugAvailable(string $slug): bool
    {
        return null === get_page_by_path($slug, OBJECT, ['post','page','attachment']);
    }

    public function create(string $type, array $fields, string $planId): array
    {
        $fail = static fn(string $error): array => ['success'=>false,'post_id'=>0,'error'=>$error];
        if (! $this->canCreate($type)) { return $fail('permission-denied'); }
        $slug = $fields['post_name'] ?? '';
        if ('' === $slug || ! preg_match('/^sm-plan-[a-f0-9]{24}$/D', $planId)) { return $fail('invalid-target'); }
        // Atomic option claim serializes Site Manager draft creation for this slug.
        // A crash leaves a fail-closed claim, requiring administrator reconciliation.
        $lock = 'site_manager_create_lock_' . hash('sha256', $slug);
        if (! add_option($lock, $planId, '', false)) { return $fail('creation-in-progress'); }
        try {
            if (! $this->slugAvailable($slug)) { return $fail('slug-unavailable'); }
            $payload = array_intersect_key($fields, array_flip(['post_title','post_name','post_content','post_excerpt']));
            $payload['post_type'] = $type;
            $payload['post_status'] = 'draft';
            $payload['post_parent'] = 0;
            $payload['meta_input'] = [self::MARKER=>$planId];
            $id = wp_insert_post(wp_slash($payload), true);
            if (is_wp_error($id) || (int) $id < 1) { return $fail('insert-failed'); }
            return ['success'=>true,'post_id'=>(int) $id,'error'=>''];
        } finally {
            delete_option($lock);
        }
    }

    public function read(int $id): ?array
    {
        $post = get_post($id);
        if (! $post) { return null; }
        // Capture more than the editable fields to prevent trashing a changed draft.
        $state = (new NativeContentRuntime())->read($id);
        if (null === $state) { return null; }
        $state['creation_plan'] = (string) get_post_meta($id, self::MARKER, true);
        $state['post_author'] = (int) $post->post_author;
        $state['post_parent'] = (int) $post->post_parent;
        $state['post_password'] = (string) $post->post_password;
        $state['comment_status'] = (string) $post->comment_status;
        $state['ping_status'] = (string) $post->ping_status;
        $state['menu_order'] = (int) $post->menu_order;
        // Hash other metadata and term associations locally; never return raw values.
        $meta = get_post_meta($id);
        $state['meta_fingerprint'] = hash('sha256', CanonicalJson::encode($meta));
        $terms = wp_get_object_terms($id, get_object_taxonomies($post->post_type), ['fields'=>'tt_ids']);
        if (is_wp_error($terms)) { return null; }
        sort($terms, SORT_NUMERIC);
        $state['term_ids'] = $terms;
        return $state;
    }

    public function trash(int $id, string $planId, string $expectedFingerprint): bool
    {
        if (! current_user_can('delete_post', $id) || ! defined('EMPTY_TRASH_DAYS') || EMPTY_TRASH_DAYS <= 0) { return false; }
        $state = $this->read($id);
        if (null === $state || 'draft' !== $state['post_status'] || $planId !== $state['creation_plan']
            || ! hash_equals($expectedFingerprint, hash('sha256', CanonicalJson::encode($state)))) { return false; }
        // Do not permanently delete; the ordinary WordPress trash is recoverable.
        $result = wp_trash_post($id);
        $after = get_post($id);
        return (bool) $result && $after && 'trash' === $after->post_status;
    }
}
