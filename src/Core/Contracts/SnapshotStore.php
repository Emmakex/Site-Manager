<?php
declare(strict_types=1);

namespace SiteManager\Core\Contracts;

interface SnapshotStore
{
    /** @param array<string,mixed> $snapshot */
    public function put(string $id, array $snapshot): void;

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array;
}
