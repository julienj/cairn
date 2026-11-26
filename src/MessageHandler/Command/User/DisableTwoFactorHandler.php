<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\DisableTwoFactor;
use App\Repository\UserRepository;
use App\Security\TwoFactorCodes;
use App\Security\TwoFactorPolicy;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An account the instance holds to a second factor cannot turn it off: only the administration resets it, and
 * the person sets it up again at once.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DisableTwoFactorHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorPolicy $policy,
        private readonly TwoFactorCodes $codes,
    ) {
    }

    public function __invoke(DisableTwoFactor $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);
        if (!$user->hasTotp()) {
            throw UserException::twoFactorNotOn();
        }
        if ($this->policy->requires($user)) {
            throw UserException::twoFactorRequired();
        }
        if (!$this->codes->isValid($user, $command->code)) {
            throw UserException::wrongCode();
        }

        $user->disableTotp();
    }
}
