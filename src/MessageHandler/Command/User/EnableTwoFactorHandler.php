<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\EnableTwoFactor;
use App\Repository\UserRepository;
use App\Security\TwoFactorCodes;
use App\Security\TwoFactorPolicy;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The code must be the one of the secret the person scanned, so that a second factor is never turned on without
 * working. The open sessions go on: the person just proved the code, and nothing else changed.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class EnableTwoFactorHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorPolicy $policy,
        private readonly TwoFactorCodes $codes,
    ) {
    }

    public function __invoke(EnableTwoFactor $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);
        if (!$this->policy->isAvailableTo($user)) {
            throw UserException::signsInWithSso($user->getFullName());
        }
        if ($user->hasTotp()) {
            throw UserException::twoFactorAlreadyOn();
        }
        if (!$this->codes->isValidForSecret($user, $command->secret, $command->code)) {
            throw UserException::wrongCode();
        }

        $user->enableTotp($command->secret);
    }
}
