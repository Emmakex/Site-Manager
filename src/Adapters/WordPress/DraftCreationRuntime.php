<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

/** Bounded draft lifecycle; no arbitrary metadata or publication. */
interface DraftCreationRuntime
{
    public function canCreate(string $type): bool;
    public function sanitize(string $field, mixed $value): string;
    public function slugAvailable(string $slug): bool;
    /** @param array<string,string> $fields @return array{success:bool,post_id:int,error:string} */
    public function create(string $type, array $fields, string $planId): array;
    /** @return array<string,mixed>|null */
    public function read(int $id): ?array;
    public function trash(int $id, string $planId, string $expectedFingerprint): bool;
}
