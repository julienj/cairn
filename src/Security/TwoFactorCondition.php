<?php

declare(strict_types=1);

namespace App\Security;

use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Condition\TwoFactorConditionInterface;

/**
 * A caller that signs in again someone who already went through the second factor on this very session (the
 * password change does) says so on the request, and is not asked for a code a second time.
 */
final class TwoFactorCondition implements TwoFactorConditionInterface
{
    public const string SKIP = '_two_factor_done';

    public function shouldPerformTwoFactorAuthentication(AuthenticationContextInterface $context): bool
    {
        return !$context->getRequest()->attributes->getBoolean(self::SKIP);
    }
}
