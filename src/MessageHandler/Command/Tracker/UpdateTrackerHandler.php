<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tracker;

use App\Message\Command\Tracker\UpdateTracker;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\TrackerRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateTrackerHandler
{
    public function __construct(
        private readonly TrackerRepository $trackers,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(UpdateTracker $command): void
    {
        $tracker = $this->trackers->find($command->trackerId) ?? throw TrackerException::notFound('Tracker', $command->trackerId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::TrackerManage, $tracker->getProject());

        if (null !== $command->name) {
            $tracker->rename($command->name);
        }
        if (null !== $command->description) {
            $tracker->setDescription($command->description);
        }
        if (null !== $command->private) {
            $command->private ? $tracker->makePrivate() : $tracker->makePublic();
        }
        if (null !== $command->archived) {
            $command->archived ? $tracker->archive() : $tracker->unarchive();
        }
        if (null !== $command->position) {
            ListOrder::move($tracker, $this->trackers->findByProject($tracker->getProject(), includeArchived: true), $command->position);
        }
    }
}
