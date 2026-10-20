<?php
declare(strict_types=1);

namespace SiteManager\Core\Governance;

final class GovernanceDecision implements \JsonSerializable
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly bool $allowed,
        public readonly array $reasons = [],
    ) {}

    public function jsonSerialize(): array
    {
        return ['allowed'=>$this->allowed,'reasons'=>$this->reasons];
    }
}
