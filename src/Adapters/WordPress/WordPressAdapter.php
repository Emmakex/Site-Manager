<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use LogicException;
use SiteManager\Core\Contracts\PlatformAdapter;
use SiteManager\Core\Model\Capability;
use SiteManager\Core\Model\CapabilityGraph;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\PlatformIdentity;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;

final class WordPressAdapter implements PlatformAdapter
{
    public function __construct(
        private readonly ?DiscoveryService $discovery = null,
        private readonly ?ContentGovernanceService $content = null,
    ) {}

    public function id(): string { return 'wordpress-core'; }

    public function detect(): PlatformIdentity
    {
        return ($this->discovery ?? new DiscoveryService())->detect();
    }

    public function discover(): DiscoverySnapshot
    {
        return ($this->discovery ?? new DiscoveryService())->discover();
    }

    public function capabilities(DiscoverySnapshot $snapshot): CapabilityGraph
    {
        $nativeContent = false;
        foreach ((array) ($snapshot->inventory['content_types'] ?? []) as $type) {
            if (in_array((string) ($type['name'] ?? ''), ['post','page'], true)) {
                $nativeContent = true;
                break;
            }
        }

        $caps = [
            new Capability('site.read', $this->id(), true, true, true, ['site.inspect'], ['site.read']),
            new Capability('site.diagnostics', $this->id(), true, true, true, ['site.diagnostics'], ['site.diagnostics']),
            new Capability('content.read', $this->id(), true, true, true, ['content.inventory'], ['site.read']),
            new Capability(
                'content.write',
                $this->id(),
                $nativeContent,
                $nativeContent,
                $nativeContent,
                ['content.update'],
                ['content.write'],
                $nativeContent ? [] : ['Native WordPress posts/pages were not detected.'],
                ['owned_fields'=>['title','slug','content','excerpt','status'],'post_types'=>['post','page'],'confirmation'=>'explicit','reversible'=>true],
            ),
            new Capability('navigation.write', $this->id(), true, true, false, ['navigation.update'], ['navigation.write'], ['SM-2 navigation ownership and rollback are not implemented yet.']),
            new Capability('seo.write', $this->id(), true, false, false, ['seo.update'], ['seo.write'], ['SEO ownership must be resolved to a provider adapter first.']),
        ];

        foreach ((array) ($snapshot->inventory['providers'] ?? []) as $provider) {
            $id = (string) ($provider['id'] ?? 'unknown');
            $caps[] = new Capability(
                id: 'provider.' . $id,
                provider: $id,
                detected: true,
                understood: true,
                governable: false,
                operations: [],
                requiredScopes: [],
                blockers: ['Provider detected; dedicated adapter required before governance is exposed.'],
                metadata: is_array($provider) ? $provider : [],
            );
        }

        return new CapabilityGraph($caps);
    }

    public function diagnostics(DiscoverySnapshot $snapshot): array
    {
        $inventory = $snapshot->inventory;
        $checks = [
            'https'=>! empty($inventory['urls']['https']),
            'rest_url'=>'' !== (string) ($inventory['urls']['rest'] ?? ''),
            'permalinks'=>'' !== (string) ($inventory['routing']['permalink_structure'] ?? ''),
            'search_visible'=>'index' === (string) ($inventory['indexability']['search_visibility'] ?? ''),
        ];
        return [
            'ready_for_read'=>! in_array(false, [$checks['rest_url']], true),
            'checks'=>$checks,
            'fingerprint'=>$snapshot->fingerprint,
            'providers_detected'=>count((array) ($inventory['providers'] ?? [])),
            'content_write_guard'=>[
                'confirmation'=>'explicit',
                'stale_plan_protection'=>true,
                'verification'=>true,
                'rollback'=>true,
                'post_types'=>['post','page'],
            ],
        ];
    }

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        if ('content.write' === $request->capabilityId && 'content.update' === $request->operation) {
            return $this->content()->plan($request, $snapshot, $graph);
        }
        throw new LogicException('No WordPress Core planner is registered for the requested operation.');
    }

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->apply($plan, $confirmed);
        }
        throw new LogicException('No WordPress Core executor is registered for this plan.');
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->verify($plan, $result);
        }
        throw new LogicException('No WordPress Core verifier is registered for this plan.');
    }

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->rollback($plan, $result, $confirmed);
        }
        throw new LogicException('No WordPress Core rollback handler is registered for this plan.');
    }

    private function content(): ContentGovernanceService
    {
        return $this->content ?? new ContentGovernanceService();
    }
}
