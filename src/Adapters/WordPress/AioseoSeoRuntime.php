<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

interface AioseoSeoRuntime
{
    public function available(): bool;

    /** @return array<string,mixed>|null */
    public function read(int $postId): ?array;

    public function canEdit(int $postId): bool;

    /** @param array<string,string> $fields AIOSEO REST `aioseo_meta_data` keys */
    public function update(int $postId, array $fields): array;
}
