<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Config\ConfigRegistry;
use App\Entity\Enum\AuthMethod;
use App\Entity\User;
use App\Message\Command\User\UpdateUser;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\PasswordTokens;
use App\Security\SingleSignOn;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The rules hold on the account as the command leaves it: the email stays
 * unique, an external user keeps no internal-only role (the change is
 * refused while they hold one, the administrator decides what to grant
 * instead), the last active administrator stays one. A person acting on
 * their own account changes their mail grouping and the activity they hide only. An account is told
 * to sign in through the identity provider only while the instance has
 * one; going back to a password is always possible.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateUserHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly ConfigRegistry $config,
        private readonly PasswordTokens $tokens,
        private readonly SingleSignOn $sso,
    ) {
    }

    public function __invoke(UpdateUser $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);
        if (null !== $command->actorId) {
            $own = $command->actorId === $command->userId
                && null === $command->email && null === $command->firstName && null === $command->lastName && null === $command->company
                && null === $command->internal && null === $command->admin && null === $command->active
                && null === $command->authMethod;
            if (!$own) {
                throw new AccessDeniedException('Someone changes the mail grouping and the hidden activity of their own account only.');
            }
        }

        $admin = $command->admin ?? ($user->isAdmin() && false !== $command->internal);
        $internal = $admin || ($command->internal ?? $user->isInternal());
        $active = $command->active ?? $user->isActive();

        if (null !== $command->email) {
            $owner = $this->users->loadUserByIdentifier($command->email);
            if (null !== $owner && $owner->getId() !== $user->getId()) {
                throw UserException::emailTaken($owner->getEmail());
            }
        }
        if ($user->isAdmin() && $user->isActive() && (!$admin || !$active) && $this->users->countActiveAdministrators() <= 1) {
            throw UserException::lastAdministrator();
        }
        if ($user->isInternal() && !$internal) {
            $this->assertNoInternalRole($user);
        }
        $method = null !== $command->authMethod && $command->authMethod !== $user->getAuthMethod() ? $command->authMethod : null;
        if (AuthMethod::Sso === $method && !$this->sso->enabled()) {
            throw UserException::ssoNotConfigured();
        }

        if (null !== $command->email) {
            $user->changeEmail($command->email);
        }
        if (null !== $command->firstName || null !== $command->lastName) {
            $user->rename($command->firstName ?? $user->getFirstName(), $command->lastName ?? $user->getLastName());
        }
        if (null !== $command->company) {
            $user->changeCompany($command->company);
        }
        if (null !== $command->mailGrouping) {
            $user->groupMails($command->mailGrouping);
        }
        if (null !== $command->hiddenActivity) {
            $user->hideActivity($command->hiddenActivity);
        }
        if (AuthMethod::Sso === $method) {
            $user->signInWithSso();
            $this->tokens->forget($user);
        } elseif (AuthMethod::Password === $method) {
            $user->signInWithPassword();
        }
        $internal ? $user->makeInternal($admin) : $user->makeExternal();
        $active ? $user->reactivate() : $user->deactivate();
    }

    private function assertNoInternalRole(User $user): void
    {
        $definition = $this->config->definition();
        $where = [];
        foreach ($this->memberships->findByUser($user) as $membership) {
            if ($definition->role($membership->getRole())->internalOnly) {
                $where[] = $membership->getProject()?->getName() ?? $membership->getWorkspace()?->getName() ?? '';
            }
        }
        if ([] !== $where) {
            throw UserException::holdsInternalRoles($user->getFullName(), $where);
        }
    }
}
