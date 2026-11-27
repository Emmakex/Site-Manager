<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use InvalidArgumentException;
use LogicException;
use SiteManager\Core\Model\CapabilityGraph;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;
use SiteManager\Core\Planning\PlanFactory;
use SiteManager\Core\Support\CanonicalJson;

/**
 * Governs a native post/page's existing-library featured image reference.
 * Does not upload, delete, edit, or fetch attachments from external URLs.
 */
final class FeaturedImageGovernanceService
{
    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        if ('content.write' !== $request->capabilityId || 'content.featured_image' !== $request->operation
            || ! $request->write || 'explicit' !== $request->confirmationClass
            || array_diff(array_keys($request->payload), ['post_id','attachment_id'])) {
            throw new InvalidArgumentException('Unsupported featured image request.');
        }
        if (! $graph->get('content.write')?->governable) { throw new LogicException('Content is not governable.'); }
        $id = $request->payload['post_id'] ?? null;
        $image = $request->payload['attachment_id'] ?? null;
        if (! is_int($id) || $id < 1 || ! is_int($image) || $image < 1) {
            throw new InvalidArgumentException('Positive native post and image IDs required.');
        }
        $state = $this->target($id);
        $source = $this->image($image, $id);
        if (null === $state || null === $source) { throw new LogicException('Featured image target or source is not safely governable.'); }
        $previous = $state['featured_image_id'];
        $prior = $previous === 0 ? null : $this->image($previous, $id);
        if ($previous !== 0 && null === $prior) { throw new LogicException('Existing image cannot be safely restored.'); }
        return (new PlanFactory())->create('wordpress-core', $snapshot->fingerprint, $request, [
            'target'=>['post_id'=>$id,'post_type'=>$state['post_type']],
            'fields'=>['featured_image_id'=>$image],
            'precondition_fingerprint'=>$this->hash($state),
            'source_fingerprint'=>$source,
        ], [
            'target'=>['featured_image_id'=>$previous],
            'prior_source_fingerprint'=>$prior,
        ], true);
    }

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult
    {
        $this->assertPlan($plan);
        if (! $confirmed) { return new ExecutionResult(false, 'confirmation-required'); }
        $id = (int) $plan->changes['target']['post_id'];
        $image = (int) $plan->changes['fields']['featured_image_id'];
        $before = $this->target($id);
        if (null === $before || $this->hash($before) !== $plan->changes['precondition_fingerprint']
            || $this->image($image, $id) !== $plan->changes['source_fingerprint']) {
            return new ExecutionResult(false, 'stale-plan');
        }
        $previous = (int) $before['featured_image_id'];
        if ($previous > 0 && $this->image($previous, $id) !== $plan->snapshot['prior_source_fingerprint']) {
            return new ExecutionResult(false, 'stale-plan');
        }
        if ($previous !== $image) { set_post_thumbnail($id, $image); }
        $after = $this->target($id);
        // Keep the receipt even if WordPress or a hook changes the requested state.
        if (null === $after) { return new ExecutionResult(false, 'post-apply-read-failed', ['post_id'=>$id]); }
        return new ExecutionResult(true, 'applied', [
            'post_id'=>$id, 'after_fingerprint'=>$this->hash($after),
            'featured_image_id'=>$after['featured_image_id'],
        ], $plan->id);
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        $this->assertPlan($plan);
        $id = (int) $plan->changes['target']['post_id'];
        $image = (int) $plan->changes['fields']['featured_image_id'];
        $state = $result->success ? $this->target($id) : null;
        $ok = null !== $state && $state['featured_image_id'] === $image
            && $this->hash($state) === ($result->data['after_fingerprint'] ?? '')
            && $this->image($image, $id) === $plan->changes['source_fingerprint'];
        return new VerificationResult($ok, $ok ? 'verified' : 'verification-mismatch',
            ['post_id'=>$id,'featured_image_id'=>$state['featured_image_id'] ?? null]);
    }

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult
    {
        $this->assertPlan($plan);
        if (! $confirmed) { return new RollbackResult(false, 'confirmation-required'); }
        if (! $plan->reversible || ! $result->success) { return new RollbackResult(false, 'rollback-unavailable'); }
        $id = (int) $plan->changes['target']['post_id'];
        $previous = (int) $plan->snapshot['target']['featured_image_id'];
        $current = $this->target($id);
        if (null === $current || $this->hash($current) !== ($result->data['after_fingerprint'] ?? '')
            || ($previous > 0 && $this->image($previous, $id) !== $plan->snapshot['prior_source_fingerprint'])) {
            return new RollbackResult(false, 'stale-rollback');
        }
        if ($current['featured_image_id'] !== $previous) {
            if ($previous === 0) { delete_post_thumbnail($id); }
            else { set_post_thumbnail($id, $previous); }
        }
        $after = $this->target($id);
        $ok = null !== $after && $after['featured_image_id'] === $previous;
        return new RollbackResult($ok, $ok ? 'rolled-back' : 'rollback-failed',
            ['post_id'=>$id,'restored_featured_image_id'=>$previous]);
    }

    private function target(int $id): ?array
    {
        $runtime = new NativeContentRuntime();
        $post = $runtime->read($id);
        if (null === $post || ! $runtime->canEdit($id)
            || ! in_array($post['post_type'], ['post','page'], true)
            || ! in_array($post['post_status'], ['draft','pending','private','publish'], true)
            || ! post_type_supports($post['post_type'], 'thumbnail')
            || ! current_theme_supports('post-thumbnails', $post['post_type'])) { return null; }
        $post['featured_image_id'] = (int) get_post_thumbnail_id($id);
        return $post;
    }

    private function image(int $id, int $targetId): ?string
    {
        $post = get_post($id);
        if (! $post || $post->post_type !== 'attachment' || $post->post_status !== 'inherit'
            || ! current_user_can('edit_post', $id) || ! wp_attachment_is_image($id)
            || ! wp_get_attachment_image($id, 'thumbnail')) { return null; }
        // Do not reuse attachments from another nonpublic or password-protected post.
        $parentId = (int) $post->post_parent;
        if ($parentId > 0 && $parentId !== $targetId) {
            $parent = get_post($parentId);
            if (! $parent || 'publish' !== $parent->post_status || '' !== (string) $parent->post_password) { return null; }
        }
        return $this->hash([
            'id'=>$id, 'parent'=>$parentId, 'mime'=>(string) $post->post_mime_type,
            'modified'=>(string) $post->post_modified_gmt,
            'metadata'=>wp_get_attachment_metadata($id), 'file'=>get_post_meta($id, '_wp_attached_file', true),
        ]);
    }

    private function hash(array $value): string { return hash('sha256', CanonicalJson::encode($value)); }
    private function assertPlan(Plan $plan): void
    {
        if ('wordpress-core' !== $plan->adapter || 'content.write' !== $plan->request->capabilityId
            || 'content.featured_image' !== $plan->request->operation) { throw new InvalidArgumentException('Not a featured image plan.'); }
    }
}
