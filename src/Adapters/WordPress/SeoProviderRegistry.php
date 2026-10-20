<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use InvalidArgumentException;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\Plan;

final class SeoProviderRegistry
{
    /** @var array<string,SeoProviderAdapter> */
    private array $adapters = [];

    /** @param list<SeoProviderAdapter>|null $adapters */
    public function __construct(?array $adapters = null)
    {
        $adapters ??= [
            new SeoGeoThemeProviderAdapter(),
            new YoastSeoProviderAdapter(),
            new RankMathSeoProviderAdapter(),
        ];
        foreach ($adapters as $adapter) {
            if (! $adapter instanceof SeoProviderAdapter) {
                throw new InvalidArgumentException('SEO provider registry accepts SeoProviderAdapter implementations only.');
            }
            $id = $adapter->id();
            if ('' === $id || isset($this->adapters[$id])) {
                throw new InvalidArgumentException('SEO provider adapter ids must be non-empty and unique.');
            }
            $this->adapters[$id] = $adapter;
        }
    }

    public function resolve(DiscoverySnapshot $snapshot): ?SeoProviderAdapter
    {
        $seo = is_array($snapshot->inventory['seo_authority'] ?? null)
            ? $snapshot->inventory['seo_authority']
            : [];
        if (empty($seo['authority_resolved']) || ! empty($seo['conflict'])) {
            return null;
        }
        $authority = is_array($seo['authority'] ?? null) ? $seo['authority'] : [];
        $id = (string) ($authority['id'] ?? '');
        $adapter = $this->adapters[$id] ?? null;

        return $adapter instanceof SeoProviderAdapter && $adapter->supports($snapshot)
            ? $adapter
            : null;
    }

    public function resolvePlan(Plan $plan): ?SeoProviderAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->planAdapterId() === $plan->adapter) {
                return $adapter;
            }
        }
        return null;
    }

    public function get(string $id): ?SeoProviderAdapter
    {
        return $this->adapters[$id] ?? null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = array_keys($this->adapters);
        sort($ids, SORT_STRING);
        return $ids;
    }
}
