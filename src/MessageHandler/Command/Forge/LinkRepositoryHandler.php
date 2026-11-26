<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Forge;

use App\Entity\CodeRepository;
use App\Message\Command\Forge\LinkRepository;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\CodeRepositoryRepository;
use App\Repository\ProjectRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class LinkRepositoryHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly CodeRepositoryRepository $repositories,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(LinkRepository $command): CodeRepository
    {
        $project = $this->projects->find($command->projectId) ?? throw CodeRepositoryException::notFound('Project', $command->projectId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::RepositoryManage, $project);
        if (!$command->forge->isAvailable()) {
            throw CodeRepositoryException::forgeUnavailable($command->forge);
        }
        $path = CodeRepository::normalizePath($command->path);
        if (null !== $this->repositories->findOne($project, $command->forge, $path)) {
            throw CodeRepositoryException::alreadyLinked($path);
        }

        $repository = new CodeRepository($project, $command->forge, $path, CodeRepository::newSecret());
        $this->entityManager->persist($repository);

        return $repository;
    }
}
