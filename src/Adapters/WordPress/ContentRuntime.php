<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

interface ContentRuntime
{
    /** @return array<string,mixed>|null */
    public function read(int $postId): ?array;

    public function canEdit(int $postId): bool;

    public function sanitize(string $field, mixed $value): string;

    /**
     * @param array<string,string> $fields WordPress post field names.
     * @return array{success:bool,post_id:int,error:string}
     */
    public function update(int $postId, array $fields): array;
}
