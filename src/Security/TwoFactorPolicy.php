<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one place that says whether an account must hold a second factor. The accounts of the identity provider
 * never do: it has its own, which the instance cannot see.
 */
final readonly class TwoFactorPolicy
{
    public function __construct(#[Autowire('%env(enum:App\Security\TwoFactorRequirement:CAIRN_TWO_FACTOR)%')] private TwoFactorRequirement $requirement)
    {
    }

    public function isAvailableTo(User $user): bool
    {
        return !$user->usesSso();
    }

    public function requires(User $user): bool
    {
        return $this->isAvailableTo($user) && match ($this->requirement) {
            TwoFactorRequirement::Optional => false,
            TwoFactorRequirement::Internal => $user->isInternal(),
            TwoFactorRequirement::All => true,
        };
    }
}
