<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tracker;

use App\Config\ConfigRegistry;
use App\Entity\Tracker;
use App\Entity\Workflow;
use App\Message\Command\Tracker\CreateTracker;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Repository\TrackerRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateTrackerHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TrackerRepository $trackers,
        private readonly ProjectActor $actor,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateTracker $command): Tracker
    {
        $project = $this->projects->find($command->projectId) ?? throw TrackerException::notFound('Project', $command->projectId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::TrackerManage, $project);
        if (!$this->config->definition()->hasWorkflow($command->workflowId)) {
            throw TrackerException::unknownWorkflow($command->workflowId);
        }

        $position = \count($this->trackers->findByProject($project, includeArchived: true));
        $tracker = new Tracker($project, $command->name, $this->config->row(Workflow::class, $command->workflowId), $position);
        $tracker->setDescription($command->description);
        if ($command->private) {
            $tracker->makePrivate();
        }
        $this->entityManager->persist($tracker);

        return $tracker;
    }
}
