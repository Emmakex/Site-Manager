<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class ExecutionResult implements \JsonSerializable
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly array $data = [],
        public readonly string $snapshotId = '',
    ) {}

    public function jsonSerialize(): array
    {
        return ['success'=>$this->success,'status'=>$this->status,'data'=>$this->data,'snapshot_id'=>$this->snapshotId];
    }
}
