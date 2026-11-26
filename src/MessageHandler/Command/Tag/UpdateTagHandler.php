<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tag;

use App\Message\Command\Tag\UpdateTag;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\TagRepository;
use App\Security\Permission;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateTagHandler
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UpdateTag $command): void
    {
        $tag = $this->tags->find($command->tagId) ?? throw TagException::notFound('Tag', $command->tagId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::TagManage, $tag->getProject());
        if (null !== $command->name) {
            // Lock the project row so that two tags renamed or created at once are not given the same name.
            $this->entityManager->lock($tag->getProject(), LockMode::PESSIMISTIC_WRITE);
            $namesake = $this->tags->findOneByName($tag->getProject(), $command->name);
            if (null !== $namesake && $namesake !== $tag) {
                throw TagException::nameTaken(trim($command->name));
            }
        }

        if (null !== $command->name) {
            $tag->rename($command->name);
        }
        if (null !== $command->colour) {
            $tag->paint($command->colour);
        }
    }
}
