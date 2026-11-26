<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Forge;

use App\Entity\CodeRepository;
use App\Forge\ForgeApiException;
use App\Forge\ForgeApiLocator;
use App\Message\Command\Forge\UpdateRepository;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\CodeRepositoryRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every rule is checked before anything changes; then the secret, the
 * installation, the removal, in that order. The calls to the forge happen
 * inside the command's transaction: a refusal leaves the row as it was.
 * A hook created just before a failed write would be orphaned on the
 * forge, and a second "install" creates a second hook; the manager
 * removes them by hand, which is rare enough to accept.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateRepositoryHandler
{
    public function __construct(
        private readonly CodeRepositoryRepository $repositories,
        private readonly ProjectActor $actor,
        private readonly ForgeApiLocator $apis,
    ) {
    }

    public function __invoke(UpdateRepository $command): void
    {
        $repository = $this->repositories->find($command->codeRepositoryId) ?? throw CodeRepositoryException::notFound('Repository', $command->codeRepositoryId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::RepositoryManage, $repository->getProject());
        $api = $this->apis->for($repository->getForge());
        if (true === $command->installed && !$repository->isInstalled() && $api->hasInstanceHook()) {
            throw CodeRepositoryException::instanceHook($repository->getForge());
        }
        if (true === $command->installed && !$api->isConfigured()) {
            throw CodeRepositoryException::apiNotConfigured($repository->getForge());
        }

        try {
            if ($command->regenerateSecret) {
                $secret = CodeRepository::newSecret();
                if (null !== $hookId = $repository->getHookId()) {
                    $api->updateHookSecret($repository->getPath(), $hookId, $secret);
                }
                $repository->regenerateSecret($secret);
            }
            if (true === $command->installed && !$repository->isInstalled()) {
                $repository->markInstalled($api->installHook($repository->getPath(), $repository->getSecret()));
            }
            if (false === $command->installed && null !== $hookId = $repository->getHookId()) {
                // Without the instance token the forge cannot be told: the hook stays there, to remove by hand.
                if ($api->isConfigured()) {
                    $api->uninstallHook($repository->getPath(), $hookId);
                }
                $repository->markUninstalled();
            }
        } catch (ForgeApiException $e) {
            throw CodeRepositoryException::forgeRefused($e);
        }
    }
}
