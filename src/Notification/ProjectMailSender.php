<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\MailGrouping;
use App\Entity\PendingNotification;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\PendingNotificationRepository;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Sends what waits in the queue: one mail per person and per project,
 * with everything that happened to the issues they follow there, or one
 * mail per issue for those who chose so (MailGrouping, on their account
 * page). A mail leaves once nothing new came for its group for a while,
 * or when its oldest change waited long enough; both delays are shorter
 * as soon as a blocking issue is among them. Runs every thirty
 * seconds through the Scheduler (`messenger:consume scheduler_default`),
 * or by hand with `app:notifications:send`.
 *
 * Each mail is one transaction that starts by deleting its rows: the
 * database lets one sender delete them, so two workers, or a run by hand
 * during a scheduled one, never send the same change twice, wherever they
 * run. The mail message lands in the Doctrine transport in that
 * transaction: sent once, or not at all. A mail that cannot be built is
 * logged, its rows keep waiting and the others leave; a change that
 * waited for more than a day is dropped, since the issue keeps its
 * history and a mail is only worth sending while it is news. What the recipient
 * may read is decided again here (ChangeFilter), and an issue they
 * stopped following, or whoever wants no mail any more, is left out.
 */
final class ProjectMailSender
{
    /** Seconds without anything new before the mail leaves. */
    public const int CALM = 180;
    /** Seconds the oldest change waits at most. */
    public const int MAX_WAIT = 900;
    public const int URGENT_CALM = 60;
    public const int URGENT_MAX_WAIT = 180;
    /** Seconds after which a change is no longer worth a mail. */
    public const int MAX_AGE = 86400;

    public function __construct(
        private readonly PendingNotificationRepository $pending,
        private readonly IssueSubscriptionRepository $subscriptions,
        private readonly IssueTeamSubscriptionRepository $teamSubscriptions,
        private readonly ChangeFilter $filter,
        private readonly AccessControl $access,
        private readonly IssueMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $force send everything that waits, due or not
     *
     * @return int the number of mails sent
     *
     * @phpstan-impure
     */
    #[AsPeriodicTask(frequency: 30)]
    public function sendDue(bool $force = false): int
    {
        $now = $this->clock->now();
        // A worker that was down for long, or a mail that kept failing, must not end in a mail about last month.
        $dropped = $this->pending->deleteOlderThan($now->modify(\sprintf('-%d seconds', self::MAX_AGE)));
        if ($dropped > 0) {
            $this->logger->warning('{count} notification(s) waited for more than a day and were dropped without a mail.', ['count' => $dropped]);
        }

        $sent = 0;
        foreach ($this->groups() as $rows) {
            $urgent = array_any($rows, static fn (PendingNotification $row): bool => Criticality::Blocking === $row->getIssue()->getCriticality());
            if (!$force && !self::isDue($rows[0]->getCreatedAt(), $rows[\count($rows) - 1]->getCreatedAt(), $urgent, $now)) {
                continue;
            }
            try {
                if ($this->send($rows)) {
                    ++$sent;
                }
            } catch (\Throwable $exception) {
                // A mail that cannot be built must not keep the others from leaving.
                $this->logger->error('The notification mail of {recipient} could not be sent; its changes keep waiting.', [
                    'recipient' => $rows[0]->getRecipient()->getEmail(),
                    'project' => $rows[0]->getIssue()->getProject()->getKey(),
                    'exception' => $exception,
                ]);
            }
        }

        return $sent;
    }

    /**
     * What waits, oldest first, by person and project, and by issue too
     * for whoever gets a mail per issue.
     *
     * @return array<string, non-empty-list<PendingNotification>>
     */
    private function groups(): array
    {
        $groups = [];
        foreach ($this->pending->findWaiting() as $row) {
            $key = $row->getRecipient()->getId().':'.$row->getIssue()->getProject()->getId();
            if (MailGrouping::Issue === $row->getRecipient()->getMailGrouping()) {
                $key .= ':'.$row->getIssue()->getId();
            }
            $groups[$key][] = $row;
        }

        return $groups;
    }

    /**
     * Whether the changes waiting in one group leave now.
     *
     * @param bool $urgent a blocking issue is among them
     */
    public static function isDue(\DateTimeImmutable $oldest, \DateTimeImmutable $newest, bool $urgent, \DateTimeImmutable $now): bool
    {
        [$calm, $maxWait] = $urgent ? [self::URGENT_CALM, self::URGENT_MAX_WAIT] : [self::CALM, self::MAX_WAIT];

        return $now->getTimestamp() - $newest->getTimestamp() >= $calm
            || $now->getTimestamp() - $oldest->getTimestamp() >= $maxWait;
    }

    /**
     * @param non-empty-list<PendingNotification> $rows those of one group, oldest first
     *
     * @return bool whether a mail left; the rows are gone unless another sender took them first
     */
    private function send(array $rows): bool
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Whoever deletes the rows sends them: a second sender waits here, then finds them gone.
            if ($this->pending->delete($rows) !== \count($rows)) {
                $connection->rollBack();

                return false;
            }
            $cards = $this->cards($rows);
            if ([] !== $cards) {
                $this->mailer->send($rows[0]->getRecipient(), $rows[0]->getIssue()->getProject(), $cards);
            }
            $connection->commit();

            return [] !== $cards;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * The cards of the mail: what the recipient may read today of what waited for them.
     *
     * @param non-empty-list<PendingNotification> $rows
     *
     * @return list<IssueChanges>
     */
    private function cards(array $rows): array
    {
        $recipient = $rows[0]->getRecipient();
        $issues = [];
        $changes = [];
        $followed = [];
        foreach ($rows as $row) {
            $issue = $row->getIssue();
            $issueId = (int) $issue->getId();
            $followed[$issueId] ??= $recipient->isActive() && $recipient->getMailGrouping()->sendsMails() && $this->access->canFollow($recipient, $issue)
                // still following when the mail leaves: by themselves, or through a team they are part of
                && (null !== $this->subscriptions->findOne($issue, $recipient) || [] !== $this->teamSubscriptions->findTeamsOf($issue, $recipient));
            if (!$followed[$issueId]) {
                continue;
            }
            $visible = $this->filter->visible($recipient, $issue, ...$this->filter->load($row->getEntryIds(), $row->getCommentId()));
            if (null === $visible) {
                continue;
            }
            [$entries, $comment] = $visible;
            $at = ($entries[0] ?? null)?->getAt() ?? $comment?->getCreatedAt() ?? $row->getCreatedAt();
            $issues[$issueId] = $issue;
            $changes[$issueId][] = new IssueChange($row->getActor(), $at, $entries, $comment, $row->getRule());
        }

        $cards = [];
        foreach ($issues as $issueId => $issue) {
            $cards[] = new IssueChanges($issue, $changes[$issueId]);
        }
        // Blocking issues first; the others stay in the order they first changed.
        usort($cards, static fn (IssueChanges $a, IssueChanges $b): int => (Criticality::Blocking === $b->issue->getCriticality()) <=> (Criticality::Blocking === $a->issue->getCriticality()));

        return $cards;
    }
}
