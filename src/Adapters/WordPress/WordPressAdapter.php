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
        private readonly ?SeoGovernanceService $seo = null,
        private readonly ?SeoProviderRegistry $seoProviders = null,
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

        $seo = $this->seoInventory($snapshot);
        $seoAuthority = is_array($seo['authority'] ?? null) ? $seo['authority'] : null;
        $seoCandidates = is_array($seo['candidates'] ?? null) ? $seo['candidates'] : [];
        $seoDetected = ! empty($seo['authority_resolved']) || [] !== $seoCandidates;
        $seoUnderstood = ! empty($seo['authority_resolved']) && empty($seo['conflict']);
        $seoProvider = $seoUnderstood ? (string) ($seoAuthority['id'] ?? $this->id()) : $this->id();
        $providerAdapter = $seoUnderstood ? $this->seoRegistry()->resolve($snapshot) : null;
        $providerReadiness = $providerAdapter?->readiness($snapshot) ?? [];
        $seoGovernable = $seoUnderstood && null !== $providerAdapter && ! empty($providerReadiness['ready']);

        $seoBlockers = [];
        if (! $seoDetected) {
            $seoBlockers[] = 'SEO ownership could not be resolved from the current WordPress runtime.';
        } elseif (! empty($seo['conflict'])) {
            $seoBlockers[] = 'Multiple SEO ownership candidates were detected; writes remain blocked until the conflict is resolved.';
        } elseif ($seoUnderstood && null === $providerAdapter) {
            $seoBlockers[] = 'SEO ownership is resolved to ' . $seoProvider . ', but no registered provider adapter is available.';
        } elseif ($seoUnderstood && ! $seoGovernable) {
            $seoBlockers[] = 'SEO ownership is resolved to ' . $seoProvider . ', but its provider adapter is not ready for safe writes.';
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
                    'provider_adapter'=>$providerAdapter?->id(),
                    'plan_adapter'=>$providerAdapter?->planAdapterId(),
                    'write_adapter_ready'=>$seoGovernable,
                    'write_mode'=>$providerReadiness['write_mode'] ?? ($seo['write_mode'] ?? null),
                    'writable_inputs'=>is_array($providerReadiness['writable_inputs'] ?? null)
                        ? $providerReadiness['writable_inputs']
                        : (is_array($seo['writable_inputs'] ?? null) ? $seo['writable_inputs'] : []),
                    'derived_outputs'=>is_array($providerReadiness['derived_outputs'] ?? null)
                        ? $providerReadiness['derived_outputs']
                        : (is_array($seo['derived_outputs'] ?? null) ? $seo['derived_outputs'] : []),
                    'confirmation'=>'explicit',
                    'reversible'=>$seoGovernable && ! empty($providerReadiness['reversible']),
                    'stale_plan_protection'=>$seoGovernable && ! empty($providerReadiness['stale_plan_protection']),
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
        $seo = $this->seoInventory($snapshot);
        $seoAdapter = $this->seoRegistry()->resolve($snapshot);
        $readiness = $seoAdapter?->readiness($snapshot) ?? [];

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
            'seo_write_guard'=>[
                'provider'=>(string) (($seo['authority']['id'] ?? '')),
                'registered_provider_adapters'=>$this->seoRegistry()->ids(),
                'resolved_provider_adapter'=>$seoAdapter?->id(),
                'plan_adapter'=>$seoAdapter?->planAdapterId(),
                'write_mode'=>$readiness['write_mode'] ?? ($seo['write_mode'] ?? null),
                'writable_inputs'=>is_array($readiness['writable_inputs'] ?? null) ? $readiness['writable_inputs'] : [],
                'derived_outputs'=>is_array($readiness['derived_outputs'] ?? null) ? $readiness['derived_outputs'] : [],
                'confirmation'=>'explicit',
                'stale_plan_protection'=>! empty($readiness['stale_plan_protection']),
                'verification'=>! empty($readiness['verification']),
                'rollback'=>! empty($readiness['reversible']),
            ],
            'seo_ownership'=>$seo,
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
        if ('seo.write' === $request->capabilityId && 'seo.update' === $request->operation) {
            $provider = $this->seoRegistry()->resolve($snapshot);
            if (null === $provider) {
                throw new LogicException('No registered SEO provider adapter can plan this operation.');
            }
            return $provider->plan($request, $snapshot, $graph);
        }
        throw new LogicException('No WordPress planner is registered for the requested operation.');
    }

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->apply($plan, $confirmed);
        }
        if ('navigation.write' === $plan->request->capabilityId && 'navigation.update' === $plan->request->operation) {
            return $this->navigation()->apply($plan, $confirmed);
        }
        if ('seo.write' === $plan->request->capabilityId && 'seo.update' === $plan->request->operation) {
            $provider = $this->seoRegistry()->resolvePlan($plan);
            if (null === $provider) {
                throw new LogicException('No registered SEO provider adapter can execute this plan.');
            }
            return $provider->apply($plan, $confirmed);
        }
        throw new LogicException('No WordPress executor is registered for this plan.');
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->verify($plan, $result);
        }
        if ('navigation.write' === $plan->request->capabilityId && 'navigation.update' === $plan->request->operation) {
            return $this->navigation()->verify($plan, $result);
        }
        if ('seo.write' === $plan->request->capabilityId && 'seo.update' === $plan->request->operation) {
            $provider = $this->seoRegistry()->resolvePlan($plan);
            if (null === $provider) {
                throw new LogicException('No registered SEO provider adapter can verify this plan.');
            }
            return $provider->verify($plan, $result);
        }
        throw new LogicException('No WordPress verifier is registered for this plan.');
    }

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult
    {
        if ('content.write' === $plan->request->capabilityId && 'content.update' === $plan->request->operation) {
            return $this->content()->rollback($plan, $result, $confirmed);
        }
        if ('navigation.write' === $plan->request->capabilityId && 'navigation.update' === $plan->request->operation) {
            return $this->navigation()->rollback($plan, $result, $confirmed);
        }
        if ('seo.write' === $plan->request->capabilityId && 'seo.update' === $plan->request->operation) {
            $provider = $this->seoRegistry()->resolvePlan($plan);
            if (null === $provider) {
                throw new LogicException('No registered SEO provider adapter can roll back this plan.');
            }
            return $provider->rollback($plan, $result, $confirmed);
        }
        throw new LogicException('No WordPress rollback handler is registered for this plan.');
    }

    private function content(): ContentGovernanceService
    {
        return $this->content ?? new ContentGovernanceService();
    }

    private function navigation(): NavigationGovernanceService
    {
        return $this->navigation ?? new NavigationGovernanceService();
    }

    private function seoRegistry(): SeoProviderRegistry
    {
        if (null !== $this->seoProviders) {
            return $this->seoProviders;
        }
        if (null !== $this->seo) {
            return new SeoProviderRegistry([new SeoGeoThemeProviderAdapter($this->seo)]);
        }
        return new SeoProviderRegistry();
    }

    /** @return array<string,mixed> */
    private function seoInventory(DiscoverySnapshot $snapshot): array
    {
        return is_array($snapshot->inventory['seo_authority'] ?? null)
            ? $snapshot->inventory['seo_authority']
            : [];
    }
}
