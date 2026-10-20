<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class DiscoverySnapshot implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $inventory
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $generatedAt,
        public readonly string $fingerprint,
        public readonly PlatformIdentity $platform,
        public readonly array $inventory,
        public readonly array $metadata = [],
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'generated_at' => $this->generatedAt,
            'fingerprint' => $this->fingerprint,
            'platform' => $this->platform->jsonSerialize(),
            'inventory' => $this->inventory,
            'metadata' => $this->metadata,
        ];
    }
}
