<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

interface NavigationRuntime
{
    /** @return array<string,mixed>|null */
    public function read(string $provider, int $targetId): ?array;

    public function canEdit(string $provider, int $targetId): bool;

    public function sanitize(string $provider, string $field, mixed $value): mixed;

    /**
     * @param array<string,mixed> $fields Normalized provider-owned navigation fields.
     * @return array{success:bool,target_id:int,error:string}
     */
    public function update(string $provider, int $targetId, array $fields): array;
}
