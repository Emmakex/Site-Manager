<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

interface RankMathSeoRuntime
{
    public function available(): bool;

    /** @return array<string,mixed>|null */
    public function read(int $postId): ?array;

    public function canEdit(int $postId): bool;

    /** @param array<string,string> $fields full Rank Math post-meta keys */
    public function update(int $postId, array $fields): array;
}
