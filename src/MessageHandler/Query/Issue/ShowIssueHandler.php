<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Issue;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Message\Query\Issue\IssueDetail;
use App\Message\Query\Issue\ShowIssue;
use App\Message\Query\Issue\TimelineEntry;
use App\Repository\EntityList;
use App\Security\AccessControl;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The issue is checked with AccessControl::canSee(). Its comments, journal
 * entries and attachments are filtered in SQL: externals never get private
 * ones, nor the files of private comments. That is the SQL side of
 * AccessControl::canSeeComment() and canSeeAttachment(), which
 * ShowIssueVisibilityTest compares on the development dataset.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ShowIssueHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function __invoke(ShowIssue $query): ?IssueDetail
    {
        $issue = $this->entityManager->createQueryBuilder()
            ->select('i', 't', 'p', 'w', 'au', 'a', 'at')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->join('p.workspace', 'w')
            ->join('i.author', 'au')
            ->leftJoin('i.assignee', 'a')
            ->leftJoin('i.assigneeTeam', 'at')
            ->where('p.id = :project')
            ->andWhere('i.number = :number')
            ->setParameter('project', $query->projectId)
            ->setParameter('number', $query->number)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$issue instanceof Issue || !$this->access->canSee($query->viewer, $issue)) {
            return null;
        }

        $publicOnly = !$query->viewer->isInternal();
        $events = $this->list(JournalEntry::class, 'e', $issue, $publicOnly, ['ea' => 'e.author', 'ec' => 'e.comment'], 'e.at');
        $comments = $this->list(Comment::class, 'c', $issue, $publicOnly, ['ca' => 'c.author'], 'c.createdAt');

        $attached = [];
        $timeline = [];
        foreach ($events as $event) {
            $timeline[] = TimelineEntry::ofEvent($event);
            if (null !== $event->getComment()) {
                $attached[(int) $event->getComment()->getId()] = true;
            }
        }
        foreach ($comments as $comment) {
            if (!isset($attached[(int) $comment->getId()])) {
                $timeline[] = TimelineEntry::ofComment($comment);
            }
        }
        usort($timeline, static fn (TimelineEntry $a, TimelineEntry $b): int => $a->at <=> $b->at);

        return new IssueDetail(
            $issue,
            $this->workflows->placeOf($issue),
            $timeline,
            $this->attachments($issue, $publicOnly),
        );
    }

    /**
     * The files of the issue and of the comments the viewer may see, oldest
     * first, with their uploader.
     *
     * @return list<Attachment>
     */
    private function attachments(Issue $issue, bool $publicOnly): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('f', 'fu')
            ->from(Attachment::class, 'f')
            ->join('f.uploadedBy', 'fu')
            ->leftJoin('f.comment', 'fc')
            ->where('f.issue = :issue')
            ->setParameter('issue', $issue)
            ->orderBy('f.createdAt')
            ->addOrderBy('f.id');
        if ($publicOnly) {
            $queryBuilder->andWhere('fc.id IS NULL OR fc.private = false');
        }

        return EntityList::of($queryBuilder->getQuery(), Attachment::class);
    }

    /**
     * Comments or journal entries of the issue, oldest first, with what
     * the page shows of them joined.
     *
     * @template T of Comment|JournalEntry
     *
     * @param class-string<T>       $class
     * @param array<string, string> $joins alias => association
     *
     * @return list<T>
     */
    private function list(string $class, string $alias, Issue $issue, bool $publicOnly, array $joins, string $orderBy): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select($alias, ...array_keys($joins))
            ->from($class, $alias)
            ->where("$alias.issue = :issue")
            ->setParameter('issue', $issue)
            ->orderBy($orderBy)
            ->addOrderBy("$alias.id");
        foreach ($joins as $joinAlias => $association) {
            $queryBuilder->leftJoin($association, $joinAlias);
        }
        if ($publicOnly) {
            $queryBuilder->andWhere("$alias.private = false");
        }

        return EntityList::of($queryBuilder->getQuery(), $class);
    }
}
