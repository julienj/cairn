<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Issue;

use App\Config\ConfigRegistry;
use App\Config\Definition\PlaceDefinition;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Query\Issue\ListSelectionActions;
use App\Message\Query\Issue\SelectionActions;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListTrackers;
use App\Message\QueryBus;
use App\Repository\EntityList;
use App\Repository\IssueOrder;
use App\Repository\ReleaseRepository;
use App\Repository\TagRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Loads the selected issues the viewer sees (Security/Visibility, an id
 * they may not see is dropped), sorted as the lists sort them, then keeps
 * what may change on all of them: the rights that hold on every project;
 * the people who are members of every project, for the assignment; the
 * open releases, the places the issues have in common and the trackers a
 * move may target, when the selection holds the issues of one project,
 * since releases and trackers belong to one.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListSelectionActionsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
        private readonly AccessControl $access,
        private readonly QueryBus $queries,
        private readonly ReleaseRepository $releases,
        private readonly TagRepository $tags,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function __invoke(ListSelectionActions $query): SelectionActions
    {
        $viewer = $query->viewer;
        $issues = $this->issues($viewer, $query->issueIds);
        if ([] === $issues) {
            return new SelectionActions([]);
        }

        $projects = [];
        foreach ($issues as $issue) {
            $projects[(int) $issue->getProject()->getId()] = $issue->getProject();
        }
        $everywhere = fn (Permission $permission): bool => array_all($projects, fn (Project $project): bool => $this->access->isGranted($viewer, $permission, $project));
        $canAssign = $everywhere(Permission::IssueAssign);
        $canComment = $everywhere(Permission::CommentCreate);
        $project = 1 === \count($projects) ? reset($projects) : null;

        return new SelectionActions(
            issues: $issues,
            project: $project,
            canAssign: $canAssign,
            assignees: $canAssign ? $this->commonMembers($projects) : [],
            canComment: $canComment,
            canCommentPrivately: $canComment && $everywhere(Permission::CommentSetPrivate),
            canSetCriticality: $everywhere(Permission::IssueSetCriticality),
            canSetPrivate: $everywhere(Permission::IssueSetPrivate),
            releases: null !== $project && $everywhere(Permission::IssueSetRelease) ? $this->releases->findByProject($project, openOnly: true) : [],
            tags: null !== $project && $everywhere(Permission::IssueSetTags) ? $this->tags->findByProject($project) : [],
            moveTrackers: null === $project ? [] : $this->moveTrackers($viewer, $project, $issues),
            places: null !== $project && $this->access->canMoveFreely($viewer, $project) ? $this->commonPlaces($issues) : [],
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Issue>
     */
    private function issues(User $viewer, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('i', 't', 'p', 'w', 'a', 'at')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->join('p.workspace', 'w')
            ->leftJoin('i.assignee', 'a')
            ->leftJoin('i.assigneeTeam', 'at')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids);
        IssueOrder::apply($queryBuilder);
        $this->visibility->restrictIssues($queryBuilder, $viewer);

        return EntityList::of($queryBuilder->getQuery(), Issue::class);
    }

    /**
     * The trackers of the project every issue may be moved to, for who
     * moves freely: those the viewer sees and those the issues are in; an
     * archived one keeps its issues and takes no other.
     *
     * @param non-empty-list<Issue> $issues
     *
     * @return list<Tracker>
     */
    private function moveTrackers(User $viewer, Project $project, array $issues): array
    {
        if (!$this->access->canMoveFreely($viewer, $project)) {
            return [];
        }

        $candidates = [];
        foreach ($this->queries->ask(new ListTrackers($viewer, $project)) as $tracker) {
            $candidates[(int) $tracker->getId()] = $tracker;
        }
        foreach ($issues as $issue) {
            $candidates[(int) $issue->getTracker()->getId()] ??= $issue->getTracker();
        }

        return array_values(array_filter(
            $candidates,
            static fn (Tracker $tracker): bool => !$tracker->isArchived() || array_all($issues, static fn (Issue $issue): bool => $issue->getTracker()->getId() === $tracker->getId()),
        ));
    }

    /**
     * @param array<int, Project> $projects
     *
     * @return list<User>
     */
    private function commonMembers(array $projects): array
    {
        $members = null;
        foreach ($projects as $project) {
            $ofProject = [];
            foreach ($this->queries->ask(new ListProjectMembers($project)) as $member) {
                $ofProject[(int) $member->getId()] = $member;
            }
            $members = null === $members ? $ofProject : array_intersect_key($members, $ofProject);
        }

        return array_values($members ?? []);
    }

    /**
     * The places every issue may stand on without leaving its tracker: a
     * place is the same in each workflow that uses it, so the selection is
     * taken to one whatever the trackers it mixes.
     *
     * @param non-empty-list<Issue> $issues
     *
     * @return list<PlaceDefinition>
     */
    private function commonPlaces(array $issues): array
    {
        $definition = $this->config->definition();
        $workflowIds = array_unique(array_map(static fn (Issue $issue): string => $issue->getTracker()->getWorkflowId(), $issues));

        return array_values(array_filter(
            $definition->places,
            static fn (PlaceDefinition $place): bool => array_all($workflowIds, static fn (string $id): bool => $definition->workflow($id)->hasPlace($place->id)),
        ));
    }
}
