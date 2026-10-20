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
        private readonly ?NavigationGovernanceService $navigation = null,
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

        $navigationProviders = [];
        $navigationDetected = false;
        $navigationGovernable = false;
        foreach ((array) ($snapshot->inventory['navigation']['providers'] ?? []) as $provider) {
            if (! is_array($provider)) { continue; }
            $id = (string) ($provider['id'] ?? '');
            if (! in_array($id, [NativeNavigationRuntime::CLASSIC, NativeNavigationRuntime::BLOCK], true)) { continue; }
            $navigationDetected = true;
            $actionable = ! empty($provider['actionable']);
            $navigationGovernable = $navigationGovernable || $actionable;
            $navigationProviders[] = [
                'id'=>$id,
                'label'=>(string) ($provider['label'] ?? $id),
                'actionable'=>$actionable,
                'target_count'=>(int) ($provider['target_count'] ?? 0),
            ];
        }

        $navigationBlockers = [];
        if (! $navigationDetected) {
            $navigationBlockers[] = 'No native WordPress navigation provider was detected.';
        } elseif (! $navigationGovernable) {
            $navigationBlockers[] = 'Native WordPress navigation was detected but no existing target is safely governable yet.';
        }

        $seo = is_array($snapshot->inventory['seo_authority'] ?? null) ? $snapshot->inventory['seo_authority'] : [];
        $seoAuthority = is_array($seo['authority'] ?? null) ? $seo['authority'] : null;
        $seoCandidates = is_array($seo['candidates'] ?? null) ? $seo['candidates'] : [];
        $seoDetected = ! empty($seo['authority_resolved']) || [] !== $seoCandidates;
        $seoUnderstood = ! empty($seo['authority_resolved']) && empty($seo['conflict']);
        $seoGovernable = $seoUnderstood && ! empty($seo['safe_to_write_seo_metadata']);
        $seoProvider = $seoUnderstood ? (string) ($seoAuthority['id'] ?? $this->id()) : $this->id();
        $seoBlockers = [];
        if (! $seoDetected) {
            $seoBlockers[] = 'SEO ownership could not be resolved from the current WordPress runtime.';
        } elseif (! empty($seo['conflict'])) {
            $seoBlockers[] = 'Multiple SEO ownership candidates were detected; writes remain blocked until the conflict is resolved.';
        } elseif ($seoUnderstood && empty($seo['write_adapter_ready'])) {
            $owner = (string) ($seoAuthority['id'] ?? 'detected-provider');
            $seoBlockers[] = 'SEO ownership is resolved to ' . $owner . ', but its write adapter is not ready.';
        } elseif (! $seoGovernable) {
            $seoBlockers[] = 'SEO ownership is understood, but no verified safe write path is available.';
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
            new Capability(
                'navigation.write',
                $this->id(),
                $navigationDetected,
                $navigationDetected,
                $navigationGovernable,
                ['navigation.update'],
                ['navigation.write'],
                $navigationBlockers,
                [
                    'providers'=>$navigationProviders,
                    'confirmation'=>'explicit',
                    'reversible'=>true,
                    'stale_plan_protection'=>true,
                    'classic_fields'=>['title','url','description','attr_title','target','classes','xfn'],
                    'block_fields'=>['title','content','status'],
                ],
            ),
            new Capability(
                'seo.write',
                $seoProvider,
                $seoDetected,
                $seoUnderstood,
                $seoGovernable,
                ['seo.update'],
                ['seo.write'],
                $seoBlockers,
                [
                    'authority'=>$seoAuthority,
                    'candidates'=>$seoCandidates,
                    'outputs'=>is_array($seo['outputs'] ?? null) ? $seo['outputs'] : [],
                    'write_adapter_ready'=>! empty($seo['write_adapter_ready']),
                    'confirmation'=>'explicit',
                ],
            ),
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
            'navigation_write_guard'=>[
                'confirmation'=>'explicit',
                'stale_plan_protection'=>true,
                'verification'=>true,
                'rollback'=>true,
                'providers'=>(array) ($inventory['navigation']['providers'] ?? []),
            ],
            'seo_ownership'=>is_array($inventory['seo_authority'] ?? null) ? $inventory['seo_authority'] : [],
        ];
    }

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        if ('content.write' === $request->capabilityId && 'content.update' === $request->operation) {
            return $this->content()->plan($request, $snapshot, $graph);
        }
        if ('navigation.write' === $request->capabilityId && 'navigation.update' === $request->operation) {
            return $this->navigation()->plan($request, $snapshot, $graph);
        }
        throw new LogicException('No WordPress Core planner is registered for the requested operation.');
    }

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->apply($plan, $confirmed);
        }
        if ('navigation.write' === $plan->request->capabilityId && 'navigation.update' === $plan->request->operation) {
            return $this->navigation()->apply($plan, $confirmed);
        }
        throw new LogicException('No WordPress Core executor is registered for this plan.');
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->verify($plan, $result);
        }
        if ('navigation.write' === $plan->request->capabilityId && 'navigation.update' === $plan->request->operation) {
            return $this->navigation()->verify($plan, $result);
        }
        throw new LogicException('No WordPress Core verifier is registered for this plan.');
    }

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->rollback($plan, $result, $confirmed);
        }
        if ('navigation.write' === $plan->request->capabilityId && 'navigation.update' === $plan->request->operation) {
            return $this->navigation()->rollback($plan, $result, $confirmed);
        }
        throw new LogicException('No WordPress Core rollback handler is registered for this plan.');
    }

    private function content(): ContentGovernanceService
    {
        return $this->content ?? new ContentGovernanceService();
    }

    private function navigation(): NavigationGovernanceService
    {
        return $this->navigation ?? new NavigationGovernanceService();
    }
}
