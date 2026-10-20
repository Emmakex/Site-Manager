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

final class RankMathSeoProviderAdapter implements SeoProviderAdapter
{
    public function __construct(
        private readonly ?RankMathSeoGovernanceService $governance = null,
        private readonly ?RankMathSeoRuntime $runtime = null,
    ) {}

    public function id(): string { return 'rank-math'; }
    public function planAdapterId(): string { return 'rank-math-post-meta'; }

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
        $ready = $this->supports($snapshot) && $this->runtime()->available();
        return [
            'ready'=>$ready,
            'provider'=>$this->id(),
            'plan_adapter'=>$this->planAdapterId(),
            'write_mode'=>'rank-math-post-meta',
            'writable_inputs'=>[
                'title'=>['source'=>'rank_math_title','reversible'=>true],
                'description'=>['source'=>'rank_math_description','reversible'=>true],
                'canonical'=>['source'=>'rank_math_canonical_url','reversible'=>true],
            ],
            'derived_outputs'=>[
                'robots'=>['provider'=>'rank-math','direct_input'=>null],
                'open-graph'=>['provider'=>'rank-math','direct_input'=>null],
                'schema'=>['provider'=>'rank-math','direct_input'=>null],
                'sitemap'=>['provider'=>'rank-math','direct_input'=>null],
            ],
            'outputs'=>[
                'title-meta'=>['owner'=>$this->id(),'writable'=>$ready],
                'description'=>['owner'=>$this->id(),'writable'=>$ready],
                'canonical'=>['owner'=>$this->id(),'writable'=>$ready],
                'robots'=>['owner'=>$this->id(),'writable'=>false],
                'open-graph'=>['owner'=>$this->id(),'writable'=>false],
                'schema'=>['owner'=>$this->id(),'writable'=>false],
                'sitemap'=>['owner'=>$this->id(),'writable'=>false],
            ],
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

    private function runtime(): RankMathSeoRuntime
    {
        return $this->runtime ?? new NativeRankMathSeoRuntime();
    }

    private function service(): RankMathSeoGovernanceService
    {
        return $this->governance ?? new RankMathSeoGovernanceService($this->runtime());
    }
}
