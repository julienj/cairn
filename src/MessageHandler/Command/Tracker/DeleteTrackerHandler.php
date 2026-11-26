<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tracker;

use App\Message\Command\Tracker\DeleteTracker;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\AutomationRuleRepository;
use App\Repository\IssueRepository;
use App\Repository\ListViewRepository;
use App\Repository\TrackerRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes the tracker for good, which only an empty one allows: nothing
 * was ever written in it, or everything was moved elsewhere. The rules
 * limited to it go with it (their executions stay, without them), as do
 * the views people kept of its list; the others close ranks.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTrackerHandler
{
    public function __construct(
        private readonly TrackerRepository $trackers,
        private readonly IssueRepository $issues,
        private readonly AutomationRuleRepository $rules,
        private readonly ListViewRepository $views,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteTracker $command): void
    {
        $tracker = $this->trackers->find($command->trackerId) ?? throw TrackerException::notFound('Tracker', $command->trackerId);
        $project = $tracker->getProject();
        $this->actor->denyUnlessGranted($command->actorId, Permission::TrackerManage, $project);
        $issues = $this->issues->count(['tracker' => $tracker]);
        if ($issues > 0) {
            throw TrackerException::hasIssues($tracker->getName(), $issues);
        }

        foreach ($this->rules->findBy(['tracker' => $tracker]) as $rule) {
            $this->entityManager->remove($rule);
        }
        $this->views->forget(\sprintf('/%s/%s/issues/tracker/%d', $project->getWorkspace()->getKey(), $project->getKey(), $tracker->getId()));
        ListOrder::close($tracker, $this->trackers->findByProject($project, includeArchived: true));
        $this->entityManager->remove($tracker);
    }
}
