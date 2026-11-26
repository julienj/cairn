<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Forge;

use App\Forge\ForgeApiException;
use App\Forge\ForgeApiLocator;
use App\Message\Command\Forge\UnlinkRepository;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\CodeRepositoryRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes the link, one of the few physical deletions: it is the current
 * configuration of the project, like a membership. The webhook goes first
 * when the API installed it; a refusal keeps the link, so the manager can
 * try again or remove the hook by hand before unlinking.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UnlinkRepositoryHandler
{
    public function __construct(
        private readonly CodeRepositoryRepository $repositories,
        private readonly ProjectActor $actor,
        private readonly ForgeApiLocator $apis,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UnlinkRepository $command): void
    {
        $repository = $this->repositories->find($command->codeRepositoryId) ?? throw CodeRepositoryException::notFound('Repository', $command->codeRepositoryId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::RepositoryManage, $repository->getProject());
        $api = $this->apis->for($repository->getForge());
        // Without the instance token the forge cannot be told: the hook stays there, to remove by hand.
        if (null !== ($hookId = $repository->getHookId()) && $api->isConfigured()) {
            try {
                $api->uninstallHook($repository->getPath(), $hookId);
            } catch (ForgeApiException $e) {
                throw CodeRepositoryException::forgeRefused($e);
            }
        }
        $this->entityManager->remove($repository);
    }
}
