<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class VerificationResult implements \JsonSerializable
{
    /** @param array<string,mixed> $evidence */
    public function __construct(
        public readonly bool $verified,
        public readonly string $status,
        public readonly array $evidence = [],
    ) {}

    public function jsonSerialize(): array
    {
        return ['verified'=>$this->verified,'status'=>$this->status,'evidence'=>$this->evidence];
    }
}
