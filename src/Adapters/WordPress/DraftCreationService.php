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

final class DraftCreationService
{
    private const FIELDS = ['title'=>'post_title','slug'=>'post_name','content'=>'post_content','excerpt'=>'post_excerpt'];
    public function __construct(private readonly ?DraftCreationRuntime $runtime = null) {}

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        if ('content.write' !== $request->capabilityId || 'content.create' !== $request->operation
            || ! $request->write || 'explicit' !== $request->confirmationClass) {
            throw new InvalidArgumentException('Unsupported draft creation request.');
        }
        if (! $graph->get('content.write')?->governable) { throw new LogicException('Content is not governable.'); }
        if (array_diff(array_keys($request->payload), ['post_type','fields'])) { throw new InvalidArgumentException('Unexpected payload field.'); }
        $type = $request->payload['post_type'] ?? '';
        $input = $request->payload['fields'] ?? null;
        if (! in_array($type, ['post','page'], true) || ! is_array($input)
            || array_diff(array_keys($input), array_keys(self::FIELDS))) {
            throw new InvalidArgumentException('Only native post/page draft fields are accepted.');
        }
        $types = array_column((array) ($snapshot->inventory['content_types'] ?? []), 'name');
        if (! in_array($type, $types, true)) { throw new LogicException('Content type was not discovered.'); }
        $runtime = $this->runtime();
        if (! $runtime->canCreate($type)) { throw new LogicException('Cannot create this WordPress content type.'); }
        $fields = [];
        foreach (self::FIELDS as $key=>$native) {
            $value = $input[$key] ?? '';
            if (! is_string($value) || strlen($value) > (['title'=>512,'slug'=>200,'content'=>100000,'excerpt'=>4000][$key])) {
                throw new InvalidArgumentException('Invalid bounded draft field.');
            }
            $fields[$native] = $runtime->sanitize($native, $value);
        }
        if ('' === trim($fields['post_title']) || '' === trim($fields['post_content'])
            || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $fields['post_name'])) {
            throw new InvalidArgumentException('A title, content and explicit ASCII slug are required.');
        }
        // Keep the first cut conservative: no overwrites or ambiguous same-slug targets.
        if (! $runtime->slugAvailable($fields['post_name'])) { throw new LogicException('Draft slug is already in use.'); }
        $fields['post_status'] = 'draft';
        return (new PlanFactory())->create('wordpress-core', $snapshot->fingerprint, $request,
            ['target'=>['post_type'=>$type,'slug'=>$fields['post_name']], 'fields'=>$fields],
            ['target'=>[], 'slug_available'=>true], true);
    }

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult
    {
        $this->assertPlan($plan);
        if (! $confirmed) { return new ExecutionResult(false, 'confirmation-required'); }
        $runtime = $this->runtime();
        $type = (string) $plan->changes['target']['post_type'];
        $fields = $plan->changes['fields'];
        if (! $runtime->canCreate($type)) { return new ExecutionResult(false, 'permission-denied'); }
        if (! $runtime->slugAvailable($fields['post_name'])) { return new ExecutionResult(false, 'stale-plan'); }
        $created = $runtime->create($type, $fields, $plan->id);
        if (! $created['success'] || $created['post_id'] < 1) { return new ExecutionResult(false, 'create-failed', ['error'=>$created['error']]); }
        $id = $created['post_id'];
        $after = $runtime->read($id);
        // Return the created ID even when readback fails, for reconciliation.
        return new ExecutionResult(true, 'applied', ['post_id'=>$id,
            'after_fingerprint'=>null === $after ? '' : $this->fingerprint($after)], $plan->id);
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        $this->assertPlan($plan);
        $id = (int) ($result->data['post_id'] ?? 0);
        $current = $result->success && $id > 0 ? $this->runtime()->read($id) : null;
        $matches = null !== $current && (int) $current['ID'] === $id
            && $current['post_type'] === $plan->changes['target']['post_type']
            && $current['creation_plan'] === $plan->id
            && $this->fingerprint($current) === ($result->data['after_fingerprint'] ?? '');
        foreach ($plan->changes['fields'] as $name=>$value) {
            $matches = $matches && ($current[$name] ?? null) === $value;
        }
        return new VerificationResult($matches, $matches ? 'verified' : 'verification-mismatch', ['post_id'=>$id]);
    }

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult
    {
        $this->assertPlan($plan);
        if (! $confirmed) { return new RollbackResult(false, 'confirmation-required'); }
        $id = (int) ($result->data['post_id'] ?? 0);
        if (! $plan->reversible || ! $result->success || $id < 1) { return new RollbackResult(false, 'rollback-unavailable'); }
        $runtime = $this->runtime();
        $current = $runtime->read($id);
        $expected = (string) ($result->data['after_fingerprint'] ?? '');
        if (null === $current || '' === $expected || $this->fingerprint($current) !== $expected
            || 'draft' !== $current['post_status'] || $plan->id !== $current['creation_plan']) {
            return new RollbackResult(false, 'stale-rollback', ['post_id'=>$id]);
        }
        $ok = $runtime->trash($id, $plan->id, $expected);
        $after = $runtime->read($id);
        $ok = $ok && null !== $after && 'trash' === $after['post_status'];
        return new RollbackResult($ok, $ok ? 'rolled-back' : 'rollback-failed', ['post_id'=>$id,'trashed'=>$ok]);
    }

    private function fingerprint(array $state): string { return hash('sha256', CanonicalJson::encode($state)); }
    private function runtime(): DraftCreationRuntime { return $this->runtime ?? new NativeDraftCreationRuntime(); }
    private function assertPlan(Plan $plan): void
    {
        if ('wordpress-core' !== $plan->adapter || 'content.write' !== $plan->request->capabilityId
            || 'content.create' !== $plan->request->operation) { throw new InvalidArgumentException('Not a draft creation plan.'); }
    }
}
