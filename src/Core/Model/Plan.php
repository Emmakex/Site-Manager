<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class Plan implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $snapshot
     */
    public function __construct(
        public readonly string $id,
        public readonly string $adapter,
        public readonly string $fingerprint,
        public readonly OperationRequest $request,
        public readonly array $changes,
        public readonly array $snapshot = [],
        public readonly bool $reversible = false,
        public readonly string $createdAt = '',
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'adapter' => $this->adapter,
            'fingerprint' => $this->fingerprint,
            'request' => $this->request,
            'changes' => $this->changes,
            'snapshot' => $this->snapshot,
            'reversible' => $this->reversible,
            'created_at' => $this->createdAt,
        ];
    }
}
