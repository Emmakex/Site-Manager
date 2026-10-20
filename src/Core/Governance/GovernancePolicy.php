<?php
declare(strict_types=1);

namespace SiteManager\Core\Governance;

use SiteManager\Core\Model\CapabilityGraph;
use SiteManager\Core\Model\OperationRequest;

final class GovernancePolicy
{
    /** @param list<string> $grantedScopes */
    public function evaluate(
        OperationRequest $request,
        CapabilityGraph $graph,
        array $grantedScopes,
        bool $confirmed = false,
    ): GovernanceDecision {
        $reasons = [];
        $capability = $graph->get($request->capabilityId);

        if (null === $capability) {
            $reasons[] = 'Capability is not present in the discovered graph.';
            return new GovernanceDecision(false, $reasons);
        }
        if (! $capability->detected) { $reasons[] = 'Capability is not detected on the target.'; }
        if (! $capability->understood) { $reasons[] = 'Capability is detected but not understood by an adapter.'; }
        if (! $capability->governable) { $reasons[] = 'Capability has no safe governance provider.'; }
        if (! in_array($request->operation, $capability->operations, true)) { $reasons[] = 'Requested operation is not exposed by the capability.'; }

        $missingScopes = array_values(array_diff($capability->requiredScopes, $grantedScopes));
        if ($missingScopes) {
            $reasons[] = 'Missing scopes: ' . implode(', ', $missingScopes) . '.';
        }

        if ($request->write && 'none' !== $request->confirmationClass && ! $confirmed) {
            $reasons[] = 'Explicit confirmation is required before this write can execute.';
        }

        foreach ($capability->blockers as $blocker) {
            $reasons[] = 'Capability blocker: ' . $blocker;
        }

        return new GovernanceDecision([] === $reasons, $reasons);
    }
}
