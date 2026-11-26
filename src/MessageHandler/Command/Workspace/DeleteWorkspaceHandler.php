<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Workspace;

use App\Entity\Membership;
use App\Entity\TeamAccess;
use App\Entity\Workspace;
use App\Message\Command\Workspace\DeleteWorkspace;
use App\MessageHandler\Command\Project\ProjectRemoval;
use App\Repository\ProjectRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Removes every project of the workspace as DeleteProject does, then the
 * access granted on the workspace and the workspace itself, in one
 * transaction: a project that cannot go (an import running on it) keeps
 * everything, and its ProjectException says which.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteWorkspaceHandler
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly ProjectRepository $projects,
        private readonly ProjectRemoval $removal,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteWorkspace $command): void
    {
        $workspace = $this->workspaces->find($command->workspaceId) ?? throw WorkspaceException::notFound($command->workspaceId);
        if (!$workspace->isArchived()) {
            throw WorkspaceException::notArchived($workspace->getName());
        }
        foreach ($this->projects->findBy(['workspace' => $workspace]) as $project) {
            $this->removal->remove($project);
        }
        $scope = ['workspace' => $workspace->getId()];
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s m WHERE m.workspace = :workspace', Membership::class))->execute($scope);
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s a WHERE a.workspace = :workspace', TeamAccess::class))->execute($scope);
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s w WHERE w.id = :workspace', Workspace::class))->execute($scope);
        $this->entityManager->detach($workspace);
    }
}
