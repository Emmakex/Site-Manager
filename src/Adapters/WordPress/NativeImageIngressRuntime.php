<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Internal native upload primitive. Not a REST endpoint or a command handler.
 * Caller must bind a reviewed manifest and parent revision to an approved plan.
 * WordPress upload URLs may be public even when the parent is a draft.
 */
final class NativeImageIngressRuntime
{
    private const PLAN_META = '_site_manager_image_ingress_plan';

    /** @return array<string,mixed>|null */
    public function target(int $parentId): ?array
    {
        $post = (new NativeContentRuntime())->read($parentId);
        if (null === $post || ! in_array($post['post_type'],['post','page'],true)
            || 'draft' !== $post['post_status'] || ! current_user_can('edit_post',$parentId)
            || ! current_user_can('upload_files')) { return null; }
        return $post;
    }

    /** @return array{attachment_id:int,verified:bool,status:string,stored_sha256?:string,stored_bytes?:int,width?:int,height?:int,mime?:string} */
    public function create(int $parentId,array $manifest,string $base64,string $planId,bool $confirmed): array
    {
        if (! $confirmed) { throw new LogicException('Explicit upload confirmation required.'); }
        if (! preg_match('/^sm-plan-[a-f0-9]{24}$/D',$planId)) {
            throw new InvalidArgumentException('Invalid reviewed plan identity.');
        }
        if (null === $this->target($parentId)) { throw new LogicException('Editable draft and upload permission required.'); }
        $guard = new ImageIngressGuard();
        $manifest = $guard->manifest($manifest);
        $bytes = $guard->decode($manifest,$base64);
        $clean = $this->reencode($bytes,$manifest);
        unset($bytes);
        $digest = hash('sha256',$clean);
        $cleanManifest = array_replace($manifest,['bytes'=>strlen($clean),'sha256'=>$digest]);
        $guard->verify($cleanManifest,$clean);

        // A prior receipt marker blocks blind repeat. This is not a transaction
        // or a substitute for the enclosing plan's replay/locking policy.
        $existing = get_posts([
            'post_type'=>'attachment','post_status'=>['inherit','publish','private','draft','pending','trash'],
            'posts_per_page'=>2,'fields'=>'ids','no_found_rows'=>true,
            'meta_key'=>self::PLAN_META,'meta_value'=>$planId,
        ]);
        if (! is_array($existing) || [] !== $existing) { throw new LogicException('Upload plan already used; reconcile first.'); }
        if (null === $this->target($parentId)) { throw new LogicException('Draft or permission changed before upload.'); }

        // Fixed generated basename, no user-selected directory or URL.
        $filename = pathinfo($manifest['filename'],PATHINFO_FILENAME).'-'.substr($digest,0,12).'.'.pathinfo($manifest['filename'],PATHINFO_EXTENSION);
        $upload = wp_upload_bits($filename,null,$clean);
        unset($clean);
        if (! empty($upload['error']) || ! is_string($upload['file'] ?? null)) {
            throw new RuntimeException('WordPress image upload failed.');
        }
        $file = $upload['file'];
        $id = wp_insert_attachment(wp_slash([
            'post_title'=>str_replace(['-','_'],' ',pathinfo($manifest['filename'],PATHINFO_FILENAME)),
            'post_mime_type'=>$manifest['mime'],
            'post_status'=>'inherit',
            'post_content'=>'',
            'post_excerpt'=>'',
            'meta_input'=>[self::PLAN_META=>$planId],
        ]),$file,$parentId,true);
        if (is_wp_error($id) || ! is_int($id) || $id < 1) {
            // No known attachment was created; remove only this newly uploaded file.
            wp_delete_file($file);
            throw new RuntimeException('Native attachment creation failed.');
        }

        // After insertion, retain the real ID on every ambiguous failure.
        // Never delete a possibly referenced attachment automatically.
        try {
            require_once ABSPATH.'wp-admin/includes/image.php';
            $disableScaling = static fn()=>false;
            add_filter('big_image_size_threshold',$disableScaling,PHP_INT_MAX);
            try {
                $meta = wp_generate_attachment_metadata($id,$file);
                if (is_array($meta)) { wp_update_attachment_metadata($id,$meta); }
            } finally {
                remove_filter('big_image_size_threshold',$disableScaling,PHP_INT_MAX);
            }
            $post = get_post($id);
            $actualFile = get_attached_file($id,true);
            $actualMeta = wp_get_attachment_metadata($id);
            $storedBytes = is_string($actualFile) && is_file($actualFile) ? filesize($actualFile) : false;
            $verified = is_int($storedBytes) && $storedBytes === $cleanManifest['bytes'] && $post && $post->post_type==='attachment' && $post->post_status==='inherit'
                && (int) $post->post_parent===$parentId && $post->post_mime_type===$manifest['mime']
                && get_post_meta($id,self::PLAN_META,true)===$planId
                && is_string($actualFile) && $actualFile===$file && is_file($actualFile)
                && hash_equals($digest,(string) hash_file('sha256',$actualFile))
                && is_array($actualMeta) && (int) ($actualMeta['width'] ?? 0)===$manifest['width']
                && (int) ($actualMeta['height'] ?? 0)===$manifest['height']
                && null !== $this->target($parentId);
            if (! $verified) { return ['attachment_id'=>$id,'verified'=>false,'status'=>'requires-reconciliation']; }
            return ['attachment_id'=>$id,'verified'=>true,'status'=>'verified',
                'stored_sha256'=>$digest,'stored_bytes'=>$storedBytes,'width'=>$manifest['width'],'height'=>$manifest['height'],'mime'=>$manifest['mime']];
        } catch (\Throwable) {
            return ['attachment_id'=>$id,'verified'=>false,'status'=>'requires-reconciliation'];
        }
    }

    private function reencode(string $bytes,array $manifest): string
    {
        $image = @imagecreatefromstring($bytes);
        if (false === $image) { throw new InvalidArgumentException('Raster decode failed.'); }
        ob_start();
        try {
            if ($manifest['mime'] !== 'image/jpeg') {
                imagealphablending($image,false);
                imagesavealpha($image,true);
            }
            $ok = match ($manifest['mime']) {
                'image/jpeg'=>function_exists('imagejpeg') && @imagejpeg($image,null,85),
                'image/png'=>function_exists('imagepng') && @imagepng($image,null,6),
                'image/webp'=>function_exists('imagewebp') && @imagewebp($image,null,85),
                default=>false,
            };
            $result = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (! $ok || ! is_string($result) || strlen($result)<1 || strlen($result)>ImageIngressGuard::MAX_BYTES) {
            throw new RuntimeException('Safe re-encoding unavailable or output exceeds image limit.');
        }
        return $result;
    }
}
