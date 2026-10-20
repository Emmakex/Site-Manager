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

/**
 * Provider-neutral SEO governance contract for WordPress.
 *
 * Implementations translate Site Manager's stable seo.* operations to the
 * real storage/runtime contract owned by one SEO provider. Merely detecting
 * a provider never makes it writable.
 */
interface SeoProviderAdapter
{
    /** Stable ownership/provider id, e.g. seo-geo-theme or yoast-seo. */
    public function id(): string;

    /** Stable adapter id stored in plans, used to route apply/verify/rollback. */
    public function planAdapterId(): string;

    /** True only when this adapter owns the SEO authority in this snapshot. */
    public function supports(DiscoverySnapshot $snapshot): bool;

    /**
     * Return bounded provider readiness/capability details.
     *
     * @return array<string,mixed>
     */
    public function readiness(DiscoverySnapshot $snapshot): array;

    public function plan(OperationRequest $request, DiscoverySnapshot $snapshot, CapabilityGraph $graph): Plan;

    public function apply(Plan $plan, bool $confirmed = false): ExecutionResult;

    public function verify(Plan $plan, ExecutionResult $result): VerificationResult;

    public function rollback(Plan $plan, ExecutionResult $result, bool $confirmed = false): RollbackResult;
}
