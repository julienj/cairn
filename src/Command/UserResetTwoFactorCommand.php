<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Command\User\ResetTwoFactor;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Takes the second factor off an account, for the administrator who lost their phone: nobody else can reset
 * theirs from the administration. Whoever runs it has the console of the instance. The account signs in with its
 * password and sets it up again, or is held to do so.
 */
#[AsCommand(name: 'app:user:reset-two-factor', description: 'Reset the second factor of an account whose person lost their phone')]
final class UserResetTwoFactorCommand
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'Email address of the account')] string $email): int
    {
        $user = $this->users->loadUserByIdentifier($email);
        if (null === $user) {
            $io->error(\sprintf('No account for %s.', $email));

            return Command::FAILURE;
        }

        try {
            $this->commands->dispatch(new ResetTwoFactor((int) $user->getId()));
        } catch (UserException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('The second factor of %s was reset: its open sessions end, and it signs in with its password.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
