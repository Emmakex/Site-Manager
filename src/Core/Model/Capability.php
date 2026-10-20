<?php
declare(strict_types=1);

namespace SiteManager\Core\Model;

final class Capability implements \JsonSerializable
{
    /**
     * @param list<string> $operations
     * @param list<string> $requiredScopes
     * @param list<string> $blockers
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly bool $detected,
        public readonly bool $understood,
        public readonly bool $governable,
        public readonly array $operations = [],
        public readonly array $requiredScopes = [],
        public readonly array $blockers = [],
        public readonly array $metadata = [],
    ) {}

    /** @param list<string> $grantedScopes */
    public function authorized(array $grantedScopes): bool
    {
        if (! $this->governable) {
            return false;
        }

        return [] === array_diff($this->requiredScopes, $grantedScopes);
    }

    public function state(array $grantedScopes = []): string
    {
        if (! $this->detected) { return 'absent'; }
        if (! $this->understood) { return 'detected'; }
        if (! $this->governable) { return 'blocked'; }
        return $this->authorized($grantedScopes) ? 'authorized' : 'governable';
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'detected' => $this->detected,
            'understood' => $this->understood,
            'governable' => $this->governable,
            'operations' => $this->operations,
            'required_scopes' => $this->requiredScopes,
            'blockers' => $this->blockers,
            'metadata' => $this->metadata,
        ];
    }
}
