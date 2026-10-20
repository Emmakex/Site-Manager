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

    /**
     * Execution callers must pass $confirmed=true only after the requested confirmation
     * class has been satisfied by the local or remote control plane.
     */
    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult;

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult;

    /** Rollback is itself a write and follows the same explicit confirmation boundary. */
    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult;
}
