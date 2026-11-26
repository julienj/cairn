<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tag;

use App\Entity\Tag;
use App\Message\Command\Tag\CreateTag;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Repository\TagRepository;
use App\Security\Permission;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateTagHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TagRepository $tags,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateTag $command): Tag
    {
        $project = $this->projects->find($command->projectId) ?? throw TagException::notFound('Project', $command->projectId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::TagManage, $project);
        // Lock the project row so that two tags created at once are not given the same name.
        $this->entityManager->lock($project, LockMode::PESSIMISTIC_WRITE);
        if (null !== $this->tags->findOneByName($project, $command->name)) {
            throw TagException::nameTaken(trim($command->name));
        }

        $tag = new Tag($project, $command->name, $command->colour);
        $this->entityManager->persist($tag);

        return $tag;
    }
}
