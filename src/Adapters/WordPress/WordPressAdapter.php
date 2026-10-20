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
    public function __construct(private readonly ?DiscoveryService $discovery = null) {}

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
        $caps = [
            new Capability('site.read', $this->id(), true, true, true, ['site.inspect'], ['site.read']),
            new Capability('site.diagnostics', $this->id(), true, true, true, ['site.diagnostics'], ['site.diagnostics']),
            new Capability('content.read', $this->id(), true, true, true, ['content.inventory'], ['site.read']),
            new Capability('content.write', $this->id(), true, true, false, ['content.update'], ['content.write'], ['SM-2 safe WordPress Core write adapter is not implemented yet.']),
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
        ];
    }

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan
    {
        throw new LogicException('SM-1 is discovery-only. Mutations start in SM-2.');
    }

    public function apply(Plan $plan): ExecutionResult
    {
        throw new LogicException('SM-1 is discovery-only. Mutations start in SM-2.');
    }

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult
    {
        throw new LogicException('SM-1 is discovery-only. Mutations start in SM-2.');
    }

    public function rollback(Plan $plan, ExecutionResult $result): RollbackResult
    {
        throw new LogicException('SM-1 is discovery-only. Mutations start in SM-2.');
    }
}
