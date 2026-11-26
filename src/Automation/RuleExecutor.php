<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\ActionType;
use App\Automation\Definition\RuleDefinition;
use App\Automation\Definition\RuleStep;
use App\Config\ConfigRegistry;
use App\Entity\BudgetLine;
use App\Entity\Comment;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Place;
use App\Entity\Team;
use App\Entity\User;
use App\Event\CommentAdded;
use App\Event\IssueMoved;
use App\Event\IssueUpdated;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\Notification\SubscriptionRules;
use App\Repository\BudgetLineRepository;
use App\Repository\TeamAccessRepository;
use App\Security\AccessControl;
use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Does what the actions of a rule say to one issue. A rule is written by
 * whoever configures the project: it acts without an author and without
 * the rights nor the workflow of anyone (it puts the issue on a place
 * whether an action leads there or not), but through the same doors as
 * a command: the intent methods of the issue, a journal entry signed with
 * the name of the rule, and IssueHandlerSupport::record() with the usual
 * event and no actor, so the assignee is subscribed, the mails leave and
 * another rule may follow. One event per action that changed something.
 * Marking the budget lines of the issue is the exception: it changes the
 * budgets, not the issue, and records nothing.
 *
 * A rule must not fail what someone did: what cannot be done (nobody
 * behind a target, a place that left the workflow) is skipped and
 * reported, and so is an action that throws, logged as an error. Only an
 * error of the database goes up: the transaction it broke cannot go on.
 */
final class RuleExecutor
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly Targets $targets,
        private readonly SubscriptionRules $subscriptions,
        private readonly AccessControl $access,
        private readonly TeamAccessRepository $teamAccesses,
        private readonly ConfigRegistry $config,
        private readonly BudgetLineRepository $budgetLines,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<StepResult> one per action, in order
     */
    public function apply(string $rule, RuleDefinition $definition, Issue $issue): array
    {
        $results = [];
        foreach ($definition->actions as $action) {
            try {
                $results[] = $this->act($rule, $action, $issue);
            } catch (DatabaseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->logger->error('An action of an automation rule failed; the rest went on.', ['rule' => $rule, 'action' => $action->type->value, 'issue' => $issue->getKey(), 'exception' => $e]);
                $results[] = StepResult::skipped($action->type, 'error');
            }
        }

        return $results;
    }

    /**
     * @param RuleStep<ActionType> $action
     */
    private function act(string $rule, RuleStep $action, Issue $issue): StepResult
    {
        return match ($action->type) {
            ActionType::Assign => $this->assign($rule, $action, $issue),
            ActionType::Move => $this->move($rule, $action, $issue),
            ActionType::SetCriticality => $this->setCriticality($rule, $action, $issue),
            ActionType::Subscribe => $this->subscribe($action, $issue),
            ActionType::Comment => $this->comment($rule, $action, $issue),
            ActionType::MakePrivate => $this->makePrivate($rule, $issue),
            ActionType::MarkBudgetLines => $this->markBudgetLines($action, $issue),
        };
    }

    /**
     * @param RuleStep<ActionType> $action
     */
    private function assign(string $rule, RuleStep $action, Issue $issue): StepResult
    {
        $target = $this->targets->resolve($action->text('target'), $issue);
        if ($target->isMissing()) {
            return StepResult::skipped(ActionType::Assign, (string) $target->reason, $target->label);
        }
        if (null !== $target->team) {
            return $this->assignToTeam($rule, $target->team, $issue);
        }
        $user = $target->user;
        // as for a person who assigns: only someone with a role on the project
        if (null !== $user && null === $this->access->roleOn($user, $issue->getProject())) {
            return StepResult::skipped(ActionType::Assign, 'not_a_member', $user->getFullName());
        }
        // given to that person already, or to nobody: neither a person nor a team
        if ($issue->getAssignee()?->getId() === $user?->getId() && null === $issue->getAssigneeTeam()) {
            return StepResult::unchanged(ActionType::Assign, (string) $user?->getFullName());
        }

        $previous = $issue->getAssigneeName();
        $issue->assignTo($user);
        $this->recordUpdate($rule, $issue, JournalEntry::changed($issue, null, 'assignee', $previous, $user?->getFullName()), $user);

        return StepResult::applied(ActionType::Assign, (string) $user?->getFullName());
    }

    /**
     * @param RuleStep<ActionType> $action
     */
    private function move(string $rule, RuleStep $action, Issue $issue): StepResult
    {
        $definition = $this->config->definition();
        $workflowId = $issue->getTracker()->getWorkflowId();
        $from = $definition->place($issue->getPlace());
        // The rule may name a place its workflow no longer has: skipped, which its executions say.
        $to = $definition->workflow($workflowId)->hasPlace($action->text('place')) ? $definition->findPlace($action->text('place')) : null;
        if (null === $to) {
            return StepResult::skipped(ActionType::Move, 'unknown_place', $action->text('place'));
        }
        if ($from->id === $to->id) {
            return StepResult::unchanged(ActionType::Move, $to->label);
        }

        $issue->moveTo($this->config->row(Place::class, $to->id));
        $this->support->arrive($issue);
        $this->support->record(new IssueMoved($issue, null, [JournalEntry::moved($issue, null, $from, $to)->byRule($rule)], $rule));

        return StepResult::applied(ActionType::Move, $to->label);
    }

    /**
     * @param RuleStep<ActionType> $action
     */
    private function setCriticality(string $rule, RuleStep $action, Issue $issue): StepResult
    {
        $criticality = Criticality::tryFrom($action->text('criticality'));
        if (null === $criticality) {
            return StepResult::skipped(ActionType::SetCriticality, 'unknown_criticality', $action->text('criticality'));
        }
        $previous = $issue->getCriticality();
        if ($previous === $criticality) {
            return StepResult::unchanged(ActionType::SetCriticality, $criticality->value);
        }

        $issue->setCriticality($criticality);
        $this->recordUpdate($rule, $issue, JournalEntry::changed($issue, null, 'criticality', $previous->value, $criticality->value));

        return StepResult::applied(ActionType::SetCriticality, $criticality->value);
    }

    /**
     * As for a person who assigns: only a team that holds a role on the
     * project, which then follows the issue as one.
     */
    private function assignToTeam(string $rule, Team $team, Issue $issue): StepResult
    {
        if (!$this->teamAccesses->holdsRoleOn($team, $issue->getProject())) {
            return StepResult::skipped(ActionType::Assign, 'not_a_member', $team->getName());
        }
        if ($issue->getAssigneeTeam() === $team) {
            return StepResult::unchanged(ActionType::Assign, $team->getName());
        }

        $previous = $issue->getAssigneeName();
        $issue->assignToTeam($team);
        $entry = JournalEntry::changed($issue, null, 'assignee', $previous, $team->getName());
        $this->support->record(new IssueUpdated($issue, null, [$entry->byRule($rule)], rule: $rule, newAssigneeTeam: $team));

        return StepResult::applied(ActionType::Assign, $team->getName());
    }

    /**
     * Following is not a change of the issue: nothing is journaled, as
     * when someone follows by themselves.
     *
     * @param RuleStep<ActionType> $action
     */
    private function subscribe(RuleStep $action, Issue $issue): StepResult
    {
        $target = $this->targets->resolve($action->text('target'), $issue);
        if (null !== $target->team) {
            // as for being given the issue: only a team that holds a role on the project
            if (!$this->teamAccesses->holdsRoleOn($target->team, $issue->getProject())) {
                return StepResult::skipped(ActionType::Subscribe, 'not_a_member', $target->team->getName());
            }
            if ($this->subscriptions->followsAsTeam($issue, $target->team)) {
                return StepResult::unchanged(ActionType::Subscribe, $target->team->getName());
            }
            // an external team cannot follow what its members may not see
            if (!$this->subscriptions->subscribeTeam($issue, $target->team)) {
                return StepResult::skipped(ActionType::Subscribe, 'cannot_see', $target->team->getName());
            }

            return StepResult::applied(ActionType::Subscribe, $target->team->getName());
        }
        $user = $target->user;
        if (null === $user) {
            return StepResult::skipped(ActionType::Subscribe, $target->reason ?? 'unknown_user', $target->label);
        }
        // as for being given the issue: only someone with a role on the project
        if (null === $this->access->roleOn($user, $issue->getProject())) {
            return StepResult::skipped(ActionType::Subscribe, 'not_a_member', $user->getFullName());
        }
        if ($this->subscriptions->isSubscribed($issue, $user)) {
            return StepResult::unchanged(ActionType::Subscribe, $user->getFullName());
        }
        // someone who may not see the issue cannot follow it
        if (!$this->subscriptions->subscribe($issue, $user)) {
            return StepResult::skipped(ActionType::Subscribe, 'cannot_see', $user->getFullName());
        }

        return StepResult::applied(ActionType::Subscribe, $user->getFullName());
    }

    /**
     * @param RuleStep<ActionType> $action
     */
    private function comment(string $rule, RuleStep $action, Issue $issue): StepResult
    {
        $body = trim($action->text('body'));
        if ('' === $body) {
            return StepResult::skipped(ActionType::Comment, 'empty_comment');
        }
        $comment = Comment::byRule($issue, $rule, $body, $action->flag('private'));
        $this->entityManager->persist($comment);
        $this->support->record(new CommentAdded($issue, null, [], $comment, $rule));

        return StepResult::applied(ActionType::Comment);
    }

    private function makePrivate(string $rule, Issue $issue): StepResult
    {
        if ($issue->isPrivate()) {
            return StepResult::unchanged(ActionType::MakePrivate);
        }

        $issue->makePrivate();
        $this->recordUpdate($rule, $issue, JournalEntry::changed($issue, null, 'visibility', 'public', 'private'));

        return StepResult::applied(ActionType::MakePrivate);
    }

    /**
     * Marks the lines of the open budgets that name the issue and stand in
     * one of the statuses the rule starts from, keeping their quantity. A
     * rule cannot estimate: a line without a quantity is left where it is
     * when the status asks for one. The issue itself does not change, as
     * when a manager marks a line: nothing is journaled, no event is
     * recorded, so nobody is told and no rule follows; the execution is
     * the trace.
     *
     * @param RuleStep<ActionType> $action
     */
    private function markBudgetLines(RuleStep $action, Issue $issue): StepResult
    {
        $status = BudgetLineStatus::from($action->text('status'));
        if (!$issue->getProject()->hasBudgets()) {
            return StepResult::skipped(ActionType::MarkBudgetLines, 'no_budget');
        }
        $from = $action->texts('from');
        $lines = array_filter($this->budgetLines->findByIssue($issue), static fn (BudgetLine $line): bool => $line->getBudget()->isOpen()
            && $status !== $line->getStatus()
            && \in_array($line->getStatus()->value, $from, true));
        if ([] === $lines) {
            return StepResult::unchanged(ActionType::MarkBudgetLines, $status->value);
        }
        $estimated = array_filter($lines, static fn (BudgetLine $line): bool => !$status->needsQuantity() || null !== $line->getQuantity());
        if ([] === $estimated) {
            return StepResult::skipped(ActionType::MarkBudgetLines, 'no_quantity', $status->value);
        }
        foreach ($estimated as $line) {
            $line->mark($status, $line->getQuantity());
        }

        return StepResult::applied(ActionType::MarkBudgetLines, $status->value);
    }

    /**
     * A property of the issue changed: its journal entry, signed, and the
     * event a person's UpdateIssue would record.
     */
    private function recordUpdate(string $rule, Issue $issue, JournalEntry $entry, ?User $newAssignee = null): void
    {
        $this->support->record(new IssueUpdated($issue, null, [$entry->byRule($rule)], newAssignee: $newAssignee, rule: $rule));
    }
}
