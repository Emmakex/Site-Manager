<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class CapabilityGraph implements \JsonSerializable
{
    /** @var array<string,Capability> */
    private array $capabilities = [];

    /** @param iterable<Capability> $capabilities */
    public function __construct(iterable $capabilities = [])
    {
        foreach ($capabilities as $capability) {
            $this->capabilities[$capability->id] = $capability;
        }
        ksort($this->capabilities);
    }

    public function get(string $id): ?Capability
    {
        return $this->capabilities[$id] ?? null;
    }

    /** @return list<Capability> */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /** @param list<string> $grantedScopes */
    public function summarize(array $grantedScopes = []): array
    {
        $summary = ['detected'=>0,'understood'=>0,'governable'=>0,'authorized'=>0,'blocked'=>0];
        foreach ($this->capabilities as $capability) {
            if ($capability->detected) { $summary['detected']++; }
            if ($capability->understood) { $summary['understood']++; }
            if ($capability->governable) { $summary['governable']++; }
            if ($capability->authorized($grantedScopes)) { $summary['authorized']++; }
            if ('blocked' === $capability->state($grantedScopes)) { $summary['blocked']++; }
        }
        return $summary;
    }

    public function jsonSerialize(): array
    {
        return ['capabilities' => $this->all()];
    }
}
