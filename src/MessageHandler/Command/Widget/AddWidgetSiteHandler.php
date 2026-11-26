<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Widget;

use App\Entity\WidgetSite;
use App\Message\Command\Widget\AddWidgetSite;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Repository\WidgetSiteRepository;
use App\Security\Permission;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class AddWidgetSiteHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly WidgetSiteRepository $sites,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AddWidgetSite $command): WidgetSite
    {
        $project = $this->projects->find($command->projectId) ?? throw WidgetException::notFound('Project', $command->projectId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::WidgetManage, $project);
        $origin = WidgetSite::normalise($command->address) ?? throw WidgetException::notAnOrigin(trim($command->address));
        // Lock the project row so that the same site declared twice at once is refused once.
        $this->entityManager->lock($project, LockMode::PESSIMISTIC_WRITE);
        if (null !== $this->sites->findOneByOrigin($project, $origin)) {
            throw WidgetException::siteTaken($origin);
        }

        $site = new WidgetSite($project, $origin);
        $this->entityManager->persist($site);

        return $site;
    }
}
