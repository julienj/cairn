<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\ConfigRegistry;
use App\Config\Definition\TransitionDefinition;
use App\Entity\Attachment;
use App\Entity\BudgetLine;
use App\Entity\Comment;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\RoleGrant;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\MembershipRepository;
use App\Repository\TeamAccessRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The one place that decides what a user may do. Takes the acting user
 * explicitly so that handlers, console commands and webhooks can ask the
 * same question as controllers; the voters are thin adapters over it.
 *
 * Administrators hold every permission and move issues freely. Internal
 * users may view every project. Everything else comes from the effective
 * role and the configuration.
 * An archived project, or a project of an archived workspace, stays
 * readable but grants no permission and no transition at all, to anyone:
 * administrators unarchive them from the administration, which
 * permissions do not govern.
 *
 * A page asks dozens of decisions about the same user: their memberships
 * are loaded once per request. reset() forgets them: the kernel calls it
 * between requests, EventListener/AccessControlResetter when a membership
 * is written.
 */
final class AccessControl implements ResetInterface
{
    /** @var array<int, list<RoleGrant>> user id => the roles granted to them and to their teams */
    private array $grants = [];

    public function __construct(
        private readonly MembershipRepository $membershipRepository,
        private readonly TeamAccessRepository $teamAccessRepository,
        private readonly ConfigRegistry $config,
    ) {
    }

    /**
     * The effective role of a user on a project or a workspace: the broadest
     * of the roles granted directly and inherited from the workspace, to
     * them or to a team they are part of. A grant may only widen what is
     * inherited, never narrow it. Being
     * internal grants no role: it only opens every project to reading.
     */
    public function roleOn(User $user, Project|Workspace $scope): ?string
    {
        $definition = $this->config->definition();
        $best = null;

        foreach ($this->grantsOf($user) as $grant) {
            $applies = $scope instanceof Project ? $grant->appliesTo($scope) : $grant->isOn($scope);
            if ($applies) {
                $best = self::broadest($definition->roleRank(...), $best, $grant->getRole());
            }
        }

        return $best;
    }

    /**
     * The API is opened by the account, not by a role on a project: it is
     * for internal users. What a token then reads and does is
     * decided by the same rules as in the pages.
     */
    public function canUseApi(User $user): bool
    {
        return $user->isActive() && $user->isInternal();
    }

    public function canView(User $user, Project|Workspace $scope): bool
    {
        return $user->isInternal() || null !== $this->roleOn($user, $scope);
    }

    /**
     * The budgets of a project that has some are read by whoever holds
     * `budget.view` or `budget.manage` there: the one place that says so,
     * for the budgets pages, their entry in the navigation and the card of
     * an issue. Reading outlives the archiving of the project, which
     * refuses every permission: what a client consumed stays consultable,
     * as the issues do. Writing does not: `budget.manage` is asked as any
     * permission.
     */
    public function canReadBudgets(User $user, Project $project): bool
    {
        if (!$project->hasBudgets()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $role = $this->roleOn($user, $project);
        $definition = $this->config->definition();

        return null !== $role
            && (\in_array($role, $definition->rolesGranted(Permission::BudgetView), true) || \in_array($role, $definition->rolesGranted(Permission::BudgetManage), true));
    }

    /**
     * The quantity of a line to estimate is a draft, or an estimate to do
     * again: whoever manages the budgets of the project reads it, since
     * they write it. Everyone else reads it once the line waits for an
     * approval. The one place that says so, for the budgets
     * pages and the card of an issue; like reading the budgets, it
     * outlives the archiving of the project.
     */
    public function canSeeQuantity(User $user, BudgetLine $line): bool
    {
        if (BudgetLineStatus::ToEstimate !== $line->getStatus() || $user->isAdmin()) {
            return true;
        }
        $role = $this->roleOn($user, $line->getBudget()->getProject());

        return null !== $role && \in_array($role, $this->config->definition()->rolesGranted(Permission::BudgetManage), true);
    }

    public function isGranted(User $user, Permission $permission, Project|Workspace $scope): bool
    {
        if ($scope instanceof Project ? $scope->isReadOnly() : $scope->isArchived()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $role = $this->roleOn($user, $scope);

        return null !== $role && \in_array($role, $this->config->definition()->rolesGranted($permission), true);
    }

    /**
     * An external user sees an issue only if the project is open to them
     * and neither the issue nor its tracker is private. SearchIssuesHandler
     * applies the same rule in SQL; keep them in step.
     */
    public function canSee(User $user, Issue $issue): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return !$issue->isPrivate() && $this->canView($user, $issue->getProject());
    }

    /**
     * Private comments are for internal users only. ShowIssueHandler
     * applies the same rule in SQL; keep them in step.
     */
    public function canSeeComment(User $user, Comment $comment): bool
    {
        return $this->canSee($user, $comment->getIssue()) && ($user->isInternal() || !$comment->isPrivate());
    }

    /**
     * The content of an issue (title, description, fields, files on the
     * issue) is its author's, as long as their role still lets them write
     * in the project; `issue.edit` grants it on the issues of others.
     */
    public function canEditIssue(User $user, Issue $issue): bool
    {
        $project = $issue->getProject();
        $own = $issue->getAuthor()->getId() === $user->getId() && $this->isGranted($user, Permission::IssueCreate, $project);

        return $own || $this->isGranted($user, Permission::IssueEdit, $project);
    }

    /**
     * The body and files of a comment are its author's, as long as their
     * role still lets them comment; `comment.edit` grants it on the
     * comments of others. Editing and deleting are the same right.
     */
    public function canEditComment(User $user, Comment $comment): bool
    {
        if (!$this->canSeeComment($user, $comment)) {
            return false;
        }
        $project = $comment->getIssue()->getProject();
        $own = $comment->getAuthor()?->getId() === $user->getId() && $this->isGranted($user, Permission::CommentCreate, $project);

        return $own || $this->isGranted($user, Permission::CommentEdit, $project);
    }

    /**
     * A pending file is seen by its uploader only; then it follows its
     * comment or its issue. ShowIssueHandler applies the same rule in SQL;
     * keep them in step.
     */
    public function canSeeAttachment(User $user, Attachment $attachment): bool
    {
        $issue = $attachment->getIssue();
        $comment = $attachment->getComment();

        return match (true) {
            null === $issue => $attachment->getUploadedBy()->getId() === $user->getId(),
            null !== $comment => $this->canSeeComment($user, $comment),
            default => $this->canSee($user, $issue),
        };
    }

    /**
     * Whether the user moves the issues of the project freely: to any
     * place of their workflow and to another tracker, without an action.
     * An administrator does, and whoever holds a role of free workflow;
     * everyone else has the actions of the workflow that name their role,
     * and nothing else. The one place that says so: nobody has both.
     */
    public function canMoveFreely(User $user, Project $project): bool
    {
        if ($project->isReadOnly()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $role = $this->roleOn($user, $project);

        return null !== $role && $this->config->definition()->role($role)->freeWorkflow;
    }

    /**
     * An action is taken by the roles it names, held to the workflow: who
     * moves freely takes none, an administrator included.
     */
    public function canTransition(User $user, TransitionDefinition $transition, Project $project): bool
    {
        if ($project->isReadOnly() || $this->canMoveFreely($user, $project)) {
            return false;
        }

        $role = $this->roleOn($user, $project);

        return null !== $role && \in_array($role, $transition->roles, true);
    }

    /**
     * The actions of its workflow the user may take on the issue from where
     * it stands: those leaving its place that name their role on its
     * project. None for who moves freely.
     *
     * @return list<TransitionDefinition>
     */
    public function transitionsFor(User $user, Issue $issue): array
    {
        $workflow = $this->config->definition()->workflow($issue->getTracker()->getWorkflowId());

        return array_values(array_filter(
            $workflow->transitionsFrom($issue->getPlace()),
            fn (TransitionDefinition $transition): bool => $this->canTransition($user, $transition, $issue->getProject()),
        ));
    }

    /**
     * Where the user may take the issue from where it stands, in the order
     * of its workflow: every other place for who moves freely, without an
     * action; otherwise one move per action of theirs. The step menu of
     * the issue page, the kanban and the selection all read this.
     *
     * @return list<PlaceMove>
     */
    public function movesFor(User $user, Issue $issue): array
    {
        $workflow = $this->config->definition()->workflow($issue->getTracker()->getWorkflowId());
        $free = $this->canMoveFreely($user, $issue->getProject());
        $transitions = $free ? [] : $this->transitionsFor($user, $issue);

        $moves = [];
        foreach ($this->config->definition()->placesOf($workflow) as $place) {
            if ($place->id === $issue->getPlace()) {
                continue;
            }
            if ($free) {
                $moves[] = new PlaceMove($place);

                continue;
            }
            foreach ($transitions as $transition) {
                if ($transition->to === $place->id) {
                    $moves[] = new PlaceMove($place, $transition);
                }
            }
        }

        return $moves;
    }

    public function reset(): void
    {
        $this->grants = [];
    }

    /**
     * @return list<RoleGrant>
     */
    private function grantsOf(User $user): array
    {
        return $this->grants[(int) $user->getId()] ??= [...$this->membershipRepository->findByUser($user), ...$this->teamAccessRepository->findByMember($user)];
    }

    /**
     * @param callable(string): int $rank 0 is the broadest role
     */
    private static function broadest(callable $rank, ?string $current, string $candidate): string
    {
        return null === $current || $rank($candidate) < $rank($current) ? $candidate : $current;
    }
}
