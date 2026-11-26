<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Issue;

use App\Config\ConfigRegistry;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\IssueTag;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\Repository\EntityList;
use App\Repository\IssueOrder;
use App\Repository\Pagination;
use App\Repository\SearchText;
use App\Security\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lists issues in the order of every list (Repository/IssueOrder), grouped
 * and sorted as asked, restricted to what the viewer may see
 * (Security/Visibility). Two queries per page: the total, then the rows
 * with tracker, project, workspace, assignee and team loaded; a third one counts
 * the groups when the list is grouped.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class SearchIssuesHandler
{
    /** The default size of a page, SearchIssues::$perPage. */
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
        private readonly ConfigRegistry $config,
    ) {
    }

    /**
     * @return Pagination<Issue>
     */
    public function __invoke(SearchIssues $query): Pagination
    {
        $viewer = $query->viewer;

        $total = (int) $this->filtered($viewer, $query)
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $placeRanks = IssueGrouping::Place === $query->group || IssueSort::Place === $query->sort ? $this->placeRanks() : [];
        $issues = EntityList::of(
            IssueOrder::apply($this->filtered($viewer, $query)->addSelect('t', 'p', 'w', 'a', 'at', 'r'), $query->group, $query->sort, $query->order, $placeRanks)
                ->setFirstResult(($query->page - 1) * $query->perPage)
                ->setMaxResults($query->perPage)
                ->getQuery(),
            Issue::class,
        );

        return new Pagination($issues, $total, $query->page, $query->perPage, null === $query->group ? [] : $this->groupTotals($viewer, $query, $query->group));
    }

    /**
     * @return array<string, int> place id => rank from 1, in the order of the instance
     */
    private function placeRanks(): array
    {
        $ranks = [];
        foreach (array_keys($this->config->definition()->places) as $index => $placeId) {
            $ranks[$placeId] = $index + 1;
        }

        return $ranks;
    }

    /**
     * @return array<string, int> group key => issues in the group
     */
    private function groupTotals(User $viewer, SearchIssues $query, IssueGrouping $group): array
    {
        $rows = $this->filtered($viewer, $query)
            ->select(IssueOrder::groupKey($group).' AS groupKey', 'COUNT(i.id) AS total')
            ->groupBy('groupKey')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $totals[IssueGrouping::key($row['groupKey'] ?? null)] = (int) ($row['total'] ?? 0);
            }
        }

        return $totals;
    }

    private function filtered(User $viewer, SearchIssues $query): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->join('p.workspace', 'w')
            ->leftJoin('i.assignee', 'a')
            ->leftJoin('i.assigneeTeam', 'at')
            ->leftJoin('i.release', 'r');

        $this->visibility->restrictIssues($queryBuilder, $viewer);

        if (null !== $query->projectId) {
            $queryBuilder->andWhere('p.id = :projectId')->setParameter('projectId', $query->projectId);
        }
        if (null !== $query->workspaceId) {
            $queryBuilder->andWhere('w.id = :workspaceId')->setParameter('workspaceId', $query->workspaceId);
        }
        if (null !== $query->assigneeId) {
            $queryBuilder->andWhere('a.id = :assigneeId')->setParameter('assigneeId', $query->assigneeId);
        }
        if (null !== $query->authorId) {
            $queryBuilder->andWhere('IDENTITY(i.author) = :authorId')->setParameter('authorId', $query->authorId);
        }

        if ([] !== $query->status) {
            IssueOrder::step($queryBuilder)->andWhere('step.status IN (:status)')->setParameter('status', $query->status);
        }
        if ([] !== $query->place) {
            $queryBuilder->andWhere('i.place IN (:place)')->setParameter('place', $query->place);
        }
        // Closed issues stay out unless asked for: by the flag, by status, or by choosing places.
        if (!$query->closed && [] === $query->place && !\in_array(PlaceStatus::Closed, $query->status, true)) {
            IssueOrder::step($queryBuilder)->andWhere('step.status != :closedStatus')->setParameter('closedStatus', PlaceStatus::Closed);
        }
        if ([] !== $query->project) {
            $queryBuilder->andWhere('p.id IN (:project)')->setParameter('project', $query->project);
        }
        if ([] !== $query->tracker) {
            $queryBuilder->andWhere('t.id IN (:tracker)')->setParameter('tracker', $query->tracker);
        }
        if ([] !== $query->criticality) {
            $queryBuilder->andWhere('i.criticality IN (:criticality)')->setParameter('criticality', $query->criticality);
        }
        if (null !== $query->teamMemberId) {
            $queryBuilder
                ->andWhere(\sprintf('IDENTITY(i.assigneeTeam) IN (SELECT IDENTITY(tm.team) FROM %s tm WHERE tm.user = :teamMemberId)', TeamMember::class))
                ->setParameter('teamMemberId', $query->teamMemberId);
        }
        if ([] !== $query->assignee || [] !== $query->team) {
            $people = array_values(array_filter($query->assignee, static fn (int $id): bool => 0 !== $id));
            $conditions = [];
            if ([] !== $people) {
                $conditions[] = 'a.id IN (:assignee)';
                $queryBuilder->setParameter('assignee', $people);
            }
            if ([] !== $query->team) {
                $conditions[] = 'at.id IN (:team)';
                $queryBuilder->setParameter('team', $query->team);
            }
            // Given to nobody: neither a person nor a team.
            if (\in_array(0, $query->assignee, true)) {
                $conditions[] = 'a.id IS NULL AND at.id IS NULL';
            }
            $queryBuilder->andWhere($queryBuilder->expr()->orX(...$conditions));
        }
        if ([] !== $query->release) {
            $releases = array_values(array_filter($query->release, static fn (int $id): bool => 0 !== $id));
            $conditions = [];
            if ([] !== $releases) {
                $conditions[] = 'r.id IN (:release)';
                $queryBuilder->setParameter('release', $releases);
            }
            if (\in_array(0, $query->release, true)) {
                $conditions[] = 'r.id IS NULL';
            }
            $queryBuilder->andWhere($queryBuilder->expr()->orX(...$conditions));
        }

        if ([] !== $query->tag) {
            // One of the tags chosen is enough; "none" is an issue no tag is tied to.
            $carried = \sprintf('SELECT 1 FROM %s it WHERE it.issue = i', IssueTag::class);
            $tags = array_values(array_filter($query->tag, static fn (int $id): bool => 0 !== $id));
            $conditions = [];
            if ([] !== $tags) {
                $conditions[] = \sprintf('EXISTS (%s AND it.tag IN (:tag))', $carried);
                $queryBuilder->setParameter('tag', $tags);
            }
            if (\in_array(0, $query->tag, true)) {
                $conditions[] = \sprintf('NOT EXISTS (%s)', str_replace(' it', ' untagged', $carried));
            }
            $queryBuilder->andWhere($queryBuilder->expr()->orX(...$conditions));
        }

        // Besides the title and the key, what named an imported issue in its former tracker, with or without its #.
        $text = trim((string) $query->q);
        if (ctype_digit($text)) {
            // A number starts the issue numbers it prefixes: 12 finds 12, 120…
            $queryBuilder
                ->andWhere('LOWER(i.title) LIKE :text OR i.key LIKE :number OR i.importedRef = :ref')
                ->setParameter('text', '%'.$text.'%')
                ->setParameter('number', '%-'.$text.'%')
                ->setParameter('ref', $text);
        } elseif ('' !== $text) {
            $queryBuilder
                ->andWhere("LOWER(i.title) LIKE :text ESCAPE '!' OR i.key = :key OR i.importedRef = :ref")
                ->setParameter('text', SearchText::containing($text))
                ->setParameter('key', mb_strtoupper($text))
                ->setParameter('ref', ltrim($text, '#'));
        }

        return $queryBuilder;
    }
}
