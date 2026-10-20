<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class OperationRequest implements \JsonSerializable
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $capabilityId,
        public readonly string $operation,
        public readonly array $payload,
        public readonly bool $write,
        public readonly string $riskClass = 'low',
        public readonly string $confirmationClass = 'none',
        public readonly string $reason = '',
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'capability_id' => $this->capabilityId,
            'operation' => $this->operation,
            'payload' => $this->payload,
            'write' => $this->write,
            'risk_class' => $this->riskClass,
            'confirmation_class' => $this->confirmationClass,
            'reason' => $this->reason,
        ];
    }
}
