<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class PlatformIdentity implements \JsonSerializable
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $platform,
        public readonly string $version,
        public readonly string $adapter,
        public readonly array $metadata = [],
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'platform' => $this->platform,
            'version' => $this->version,
            'adapter' => $this->adapter,
            'metadata' => $this->metadata,
        ];
    }
}
