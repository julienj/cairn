<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Attachment;
use App\Entity\AutomationRun;
use App\Entity\BudgetLine;
use App\Entity\CodeReference;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\IssueFieldValue;
use App\Entity\IssueLink;
use App\Entity\IssueSubscription;
use App\Entity\IssueTag;
use App\Entity\IssueTeamSubscription;
use App\Entity\JournalEntry;
use App\Entity\PendingNotification;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Erases issues for good with everything that hangs from them: the one
 * place that knows what that is, and in which order the rows go. One
 * issue (DeleteIssue) or every issue of a project (DeleteProject) alike,
 * in a few bulk statements whatever their number: nothing is loaded, so
 * no Doctrine listener runs and the files stay on disk. The caller gets
 * the uuids of those files and removes them once its transaction
 * committed (RemoveFiles).
 *
 * The links go with either end, without a word in the history of the
 * other issue, and a reference written in a text elsewhere (#MD-SPT-12)
 * reads as plain text from then on.
 */
final class IssueEraser
{
    /** What points to an issue, and by which property, in the order the foreign keys allow. */
    private const array DEPENDENTS = [
        [PendingNotification::class, 'issue'],
        [AutomationRun::class, 'issue'],
        [Attachment::class, 'issue'],
        [JournalEntry::class, 'issue'],
        [IssueLink::class, 'source'],
        [IssueLink::class, 'target'],
        [IssueSubscription::class, 'issue'],
        [IssueTeamSubscription::class, 'issue'],
        [IssueFieldValue::class, 'issue'],
        [IssueTag::class, 'issue'],
        [CodeReference::class, 'issue'],
        [BudgetLine::class, 'issue'],
        [Comment::class, 'issue'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<string> the uuids of the files that were attached to it
     */
    public function eraseIssue(Issue $issue): array
    {
        $uuids = $this->erase('i.id = :scope', (int) $issue->getId());
        $this->entityManager->detach($issue);

        return $uuids;
    }

    /**
     * Every issue of the project, and the files still pending on it.
     *
     * @return list<string> the uuids of every file of the project
     */
    public function eraseIssuesOf(Project $project): array
    {
        $uuids = $this->uuids('a.project = :scope', (int) $project->getId());
        $this->erase('IDENTITY(i.project) = :scope', (int) $project->getId());
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s a WHERE a.project = :scope', Attachment::class))->execute(['scope' => $project->getId()]);

        return $uuids;
    }

    /**
     * @return list<string>
     */
    private function erase(string $issues, int $scope): array
    {
        $selected = \sprintf('SELECT i.id FROM %s i WHERE %s', Issue::class, $issues);
        $uuids = $this->uuids(\sprintf('a.issue IN (%s)', $selected), $scope);
        foreach (self::DEPENDENTS as [$class, $property]) {
            $this->entityManager->createQuery(\sprintf('DELETE FROM %s d WHERE d.%s IN (%s)', $class, $property, $selected))->execute(['scope' => $scope]);
        }
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s i WHERE %s', Issue::class, $issues))->execute(['scope' => $scope]);

        return $uuids;
    }

    /**
     * @return list<string>
     */
    private function uuids(string $where, int $scope): array
    {
        /** @var list<Uuid|string> $uuids a column read alone comes as the database gives it, a string */
        $uuids = $this->entityManager->createQuery(\sprintf('SELECT a.uuid FROM %s a WHERE %s', Attachment::class, $where))
            ->setParameter('scope', $scope)
            ->getSingleColumnResult();

        return array_map(static fn (Uuid|string $uuid): string => ($uuid instanceof Uuid ? $uuid : Uuid::fromString($uuid))->toRfc4122(), $uuids);
    }
}
