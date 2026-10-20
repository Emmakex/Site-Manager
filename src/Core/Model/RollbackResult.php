<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class RollbackResult implements \JsonSerializable
{
    /** @param array<string,mixed> $evidence */
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly array $evidence = [],
    ) {}

    public function jsonSerialize(): array
    {
        return ['success'=>$this->success,'status'=>$this->status,'evidence'=>$this->evidence];
    }
}
