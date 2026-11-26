<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\PendingNotification;
use App\Entity\User;
use App\Message\Notification\NotifyIssueChange;
use App\Notification\ChangeFilter;
use App\Repository\IssueRepository;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Queues the change for every subscriber of the issue and every member of
 * the teams that follow it, except the one who
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
        private readonly IssueTeamSubscriptionRepository $teamSubscriptions,
        private readonly ChangeFilter $filter,
        private readonly AccessControl $access,
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

        foreach ($this->recipients($issue) as $recipient) {
            if ($recipient->getId() === $actor?->getId() || !$recipient->getMailGrouping()->sendsMails() || !$this->access->canFollow($recipient, $issue)) {
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

    /**
     * Who follows the issue, and the members of the teams that follow it:
     * read now, so whoever joined a team since is told and whoever left
     * it is not, without a subscription per person to keep in step.
     *
     * @return list<User>
     */
    private function recipients(Issue $issue): array
    {
        $recipients = [];
        foreach ([...$this->subscriptions->findSubscribers($issue), ...$this->teamSubscriptions->findMembers($issue)] as $recipient) {
            $recipients[(int) $recipient->getId()] ??= $recipient;
        }

        return array_values($recipients);
    }
}
