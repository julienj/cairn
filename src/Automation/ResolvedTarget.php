<?php

declare(strict_types=1);

namespace App\Automation;

use App\Entity\Team;
use App\Entity\User;

/**
 * Who a target of a rule leads to on one issue (Targets::resolve()): the
 * person or the team, or why there is none, and what the target reads as in the
 * report of an execution (a role, a name). `nobody` leads to nobody
 * without a reason: it is what was asked.
 */
final readonly class ResolvedTarget
{
    private function __construct(
        public ?User $user,
        public ?string $reason,
        public string $label,
        public ?Team $team = null,
    ) {
    }

    public static function to(User $user, string $label): self
    {
        return new self($user, null, $label);
    }

    public static function toTeam(Team $team): self
    {
        return new self(null, null, $team->getName(), $team);
    }

    public static function nobody(): self
    {
        return new self(null, null, '');
    }

    /**
     * @param string $reason `no_referent`, `no_internal`, `unknown_user`, `unknown_team`, `deactivated`
     */
    public static function missing(string $reason, string $label): self
    {
        return new self(null, $reason, $label);
    }

    public function isMissing(): bool
    {
        return null !== $this->reason;
    }
}
