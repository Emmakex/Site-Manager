<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class AuditEvent implements \JsonSerializable
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly string $occurredAt,
        public readonly string $correlationId,
        public readonly array $data = [],
    ) {}

    public function jsonSerialize(): array
    {
        return ['type'=>$this->type,'occurred_at'=>$this->occurredAt,'correlation_id'=>$this->correlationId,'data'=>$this->data];
    }
}
