<?php
declare(strict_types=1);

namespace SiteManager\Core\Contracts;

use SiteManager\Core\Model\CapabilityGraph;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\PlatformIdentity;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;

interface PlatformAdapter
{
    public function id(): string;

    public function detect(): PlatformIdentity;

    public function discover(): DiscoverySnapshot;

    public function capabilities(DiscoverySnapshot $snapshot): CapabilityGraph;

    /** @return array<string,mixed> */
    public function diagnostics(DiscoverySnapshot $snapshot): array;

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan;

    public function apply(Plan $plan): ExecutionResult;

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult;

    public function rollback(Plan $plan, ExecutionResult $result): RollbackResult;
}
