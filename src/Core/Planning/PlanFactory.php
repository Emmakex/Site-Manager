<?php
declare(strict_types=1);

namespace SiteManager\Core\Planning;

use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Support\CanonicalJson;

final class PlanFactory
{
    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $snapshot
     */
    public function create(
        string $adapter,
        string $fingerprint,
        OperationRequest $request,
        array $changes,
        array $snapshot = [],
        bool $reversible = false,
        ?string $createdAt = null,
    ): Plan {
        $createdAt ??= gmdate(DATE_W3C);
        $stable = CanonicalJson::encode([
            'adapter'=>$adapter,
            'fingerprint'=>$fingerprint,
            'request'=>$request,
            'changes'=>$changes,
            'snapshot'=>$snapshot,
            'reversible'=>$reversible,
        ]);
        $id = 'sm-plan-' . substr(hash('sha256', $stable), 0, 24);

        return new Plan($id, $adapter, $fingerprint, $request, $changes, $snapshot, $reversible, $createdAt);
    }
}
