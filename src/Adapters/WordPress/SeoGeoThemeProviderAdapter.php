<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use SiteManager\Core\Model\CapabilityGraph;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;

final class SeoGeoThemeProviderAdapter implements SeoProviderAdapter
{
    public function __construct(private readonly ?SeoGovernanceService $governance = null) {}

    public function id(): string
    {
        return 'seo-geo-theme';
    }

    public function planAdapterId(): string
    {
        return 'seo-geo-theme-native';
    }

    public function supports(DiscoverySnapshot $snapshot): bool
    {
        $seo = $this->seo($snapshot);
        $authority = is_array($seo['authority'] ?? null) ? $seo['authority'] : [];

        return ! empty($seo['authority_resolved'])
            && empty($seo['conflict'])
            && $this->id() === (string) ($authority['id'] ?? '');
    }

    public function readiness(DiscoverySnapshot $snapshot): array
    {
        $seo = $this->seo($snapshot);
        $ready = $this->supports($snapshot)
            && ! empty($seo['write_adapter_ready'])
            && ! empty($seo['safe_to_write_seo_metadata']);

        return [
            'ready'=>$ready,
            'provider'=>$this->id(),
            'plan_adapter'=>$this->planAdapterId(),
            'write_mode'=>$seo['write_mode'] ?? null,
            'writable_inputs'=>is_array($seo['writable_inputs'] ?? null) ? $seo['writable_inputs'] : [],
            'derived_outputs'=>is_array($seo['derived_outputs'] ?? null) ? $seo['derived_outputs'] : [],
            'outputs'=>is_array($seo['outputs'] ?? null) ? $seo['outputs'] : [],
            'reversible'=>$ready,
            'stale_plan_protection'=>$ready,
            'verification'=>$ready,
        ];
    }

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        return $this->service()->plan($request, $snapshot, $graph);
    }

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult
    {
        return $this->service()->apply($plan, $confirmed);
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        return $this->service()->verify($plan, $result);
    }

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult
    {
        return $this->service()->rollback($plan, $result, $confirmed);
    }

    /** @return array<string,mixed> */
    private function seo(DiscoverySnapshot $snapshot): array
    {
        return is_array($snapshot->inventory['seo_authority'] ?? null)
            ? $snapshot->inventory['seo_authority']
            : [];
    }

    private function service(): SeoGovernanceService
    {
        return $this->governance ?? new SeoGovernanceService();
    }
}
