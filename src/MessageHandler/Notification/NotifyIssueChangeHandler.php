<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Entity\JournalEntry;
use App\Entity\PendingNotification;
use App\Message\Notification\NotifyIssueChange;
use App\Notification\ChangeFilter;
use App\Repository\IssueRepository;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Queues the change for every subscriber of the issue, except the one who
 * acted (an automation rule tells everyone) and those who want no mail, with what each may read of it (Notification/ChangeFilter): an
 * external user waits for nothing when the change is private. No mail
 * leaves here: Notification/ProjectMailSender sends what waits, grouped
 * by person and project, once things calmed down.
 */
#[AsMessageHandler(bus: 'messenger.bus.default')]
final class NotifyIssueChangeHandler
{
    public function __construct(
        private readonly IssueRepository $issues,
        private readonly UserRepository $users,
        private readonly IssueSubscriptionRepository $subscriptions,
        private readonly ChangeFilter $filter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(NotifyIssueChange $message): void
    {
        $issue = $this->issues->find($message->issueId);
        $actor = null === $message->actorId ? null : $this->users->find($message->actorId);
        if (null === $issue || (null === $actor && null === $message->rule)) {
            return;
        }
        [$entries, $comment] = $this->filter->load($message->entryIds, $message->commentId);

        foreach ($this->subscriptions->findSubscribers($issue) as $recipient) {
            if ($recipient->getId() === $actor?->getId() || !$recipient->getMailGrouping()->sendsMails()) {
                continue;
            }
            $visible = $this->filter->visible($recipient, $issue, $entries, $comment);
            if (null === $visible) {
                continue;
            }
            [$visibleEntries, $visibleComment] = $visible;
            $entryIds = array_values(array_filter(array_map(static fn (JournalEntry $entry): ?int => $entry->getId(), $visibleEntries)));
            $this->entityManager->persist(new PendingNotification($recipient, $issue, $actor, $entryIds, $visibleComment?->getId(), $message->rule));
        }
        $this->entityManager->flush();
    }
}
