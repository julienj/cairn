<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Attachment\AvatarStorage;
use App\Entity\ApiToken;
use App\Entity\Attachment;
use App\Entity\IssueSubscription;
use App\Entity\ListView;
use App\Entity\Membership;
use App\Entity\PasswordToken;
use App\Entity\PendingNotification;
use App\Entity\WidgetToken;
use App\Message\Command\User\DeleteUser;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * What belongs to the account alone goes with it; what it did would not,
 * so an account with a history is refused (UserRepository::hasHistory()).
 * The files it sent and never attached are removed one by one, so that
 * AttachmentFileRemover takes each file.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteUserHandler
{
    /** What is the account's own, beside the notifications waiting for it. */
    private const array BELONGINGS = [Membership::class, IssueSubscription::class, ApiToken::class, WidgetToken::class, PasswordToken::class, ListView::class];

    public function __construct(
        private readonly UserRepository $users,
        private readonly AvatarStorage $avatars,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteUser $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);
        if ($user->isAdmin() && $user->isActive() && $this->users->countActiveAdministrators() <= 1) {
            throw UserException::lastAdministrator();
        }
        if ($this->users->hasHistory($user)) {
            throw UserException::hasHistory($user->getFullName());
        }

        foreach ($this->entityManager->getRepository(Attachment::class)->findBy(['uploadedBy' => $user]) as $pending) {
            $this->entityManager->remove($pending);
        }
        $scope = ['user' => $user->getId()];
        foreach (self::BELONGINGS as $class) {
            $this->entityManager->createQuery(\sprintf('DELETE FROM %s b WHERE b.user = :user', $class))->execute($scope);
        }
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s n WHERE n.recipient = :user', PendingNotification::class))->execute($scope);
        if (null !== $avatar = $user->getAvatar()) {
            $this->avatars->delete($avatar);
        }
        $this->entityManager->remove($user);
    }
}
