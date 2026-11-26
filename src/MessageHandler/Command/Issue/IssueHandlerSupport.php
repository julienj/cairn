<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Config\ConfigRegistry;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\FieldType;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Field;
use App\Entity\Issue;
use App\Entity\IssueFieldValue;
use App\Entity\JournalEntry;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use App\Event\IssueEvent;
use App\Event\IssueTransitioned;
use App\Repository\AttachmentRepository;
use App\Repository\CommentRepository;
use App\Repository\IssueFieldValueRepository;
use App\Repository\IssueRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Loading, visibility checks, attachment claiming and the recording of a
 * change shared by the issue handlers.
 */
final class IssueHandlerSupport
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly IssueRepository $issues,
        private readonly AccessControl $access,
        private readonly AttachmentRepository $attachments,
        private readonly CommentRepository $comments,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $events,
        private readonly WorkflowProvider $workflows,
        private readonly IssueFieldValueRepository $fieldValues,
        private readonly ConfigRegistry $config,
        private readonly TeamRepository $teams,
    ) {
    }

    /**
     * Applies a transition of the issue's workflow for the actor, with the
     * comment that may come with it: the one path a transition takes,
     * whether a command names it (ApplyTransition) or a place is asked for
     * and it leads there (MoveIssue). The state machine says whether the
     * issue may take it from its place.
     *
     * @param list<string> $attachments uuids of the actor's pending files sent with the comment
     */
    public function transition(Issue $issue, User $actor, TransitionDefinition $transition, ?string $comment = null, bool $commentPrivate = false, array $attachments = []): void
    {
        $project = $issue->getProject();
        $workflowId = $issue->getTracker()->getWorkflowId();
        $definition = $this->workflows->definition($workflowId);

        $this->denyUnless($this->access->canTransition($actor, $transition, $project), \sprintf('apply transition "%s"', $transition->id));

        $workflow = $this->workflows->get($workflowId);
        // An issue may stand on a step its workflow lost in the instant it was moved there: no action starts from it.
        if (!$definition->hasPlace($issue->getPlace()) || !$workflow->can($issue, $transition->id)) {
            throw IssueException::transitionUnavailable($transition->id, $issue->getPlace());
        }

        $body = trim((string) $comment);
        if ('' === $body && $transition->requiresComment) {
            throw IssueException::commentRequired($transition->id);
        }

        $written = null;
        if ('' !== $body) {
            $this->denyUnless($this->access->isGranted($actor, Permission::CommentCreate, $project), 'comment');
            $this->denyUnless(!$commentPrivate || $this->access->isGranted($actor, Permission::CommentSetPrivate, $project), 'make comments private');
            $written = new Comment($issue, $actor, $body, $commentPrivate);
            $this->entityManager->persist($written);
            $this->claimAttachments($actor, $issue, $attachments, $body, $written);
        }

        $from = $this->workflows->place($issue->getPlace());
        $workflow->apply($issue, $transition->id, ['actor' => $actor]);
        $to = $this->workflows->place($issue->getPlace());
        $this->arrive($issue);

        $this->record(new IssueTransitioned($issue, $actor, [JournalEntry::transitioned($issue, $actor, $transition->label, $from, $to, $written)], $written, $transition->id));
    }

    /**
     * The issue just took a place: it stands on top of that kanban column,
     * a step over the highest rank there. Called wherever an issue enters
     * a place (creation, action, free move, change of tracker).
     */
    public function arrive(Issue $issue): void
    {
        $top = $this->issues->topRank($issue->getTracker(), $issue->getPlace());
        $issue->rankAt(null === $top ? 0 : $top + Issue::RANK_STEP);
    }

    /**
     * Records what a command did to an issue: its journal entries are
     * persisted, the issue is touched, and the event is dispatched inside
     * the transaction for the listeners that react to it (subscriptions,
     * notifications). One call per command and per issue.
     */
    public function record(IssueEvent $event): void
    {
        foreach ($event->entries as $entry) {
            $this->entityManager->persist($entry);
        }
        $event->issue->touch();
        if (null === $event->rule) {
            $event->issue->markActivity();
        }
        $this->events->dispatch($event);
    }

    public function actor(int $actorId): User
    {
        return $this->users->find($actorId) ?? throw IssueException::notFound('User', $actorId);
    }

    /**
     * The issue, if the actor may see it; an invisible issue is reported as
     * not found so that private issues cannot be probed.
     */
    public function visibleIssue(User $actor, string $key): Issue
    {
        $issue = $this->issues->findOneByKey($key);
        if (null === $issue || !$this->access->canSee($actor, $issue)) {
            throw IssueException::notFound('Issue', $key);
        }

        return $issue;
    }

    /**
     * The person an issue is given to: an active member of the project, or
     * nobody. The same rule at creation and on reassignment.
     */
    public function assignee(?int $assigneeId, Project $project): ?User
    {
        if (null === $assigneeId) {
            return null;
        }
        $assignee = $this->actor($assigneeId);
        if (!$assignee->isActive()) {
            throw IssueException::deactivatedUser($assignee->getFullName());
        }
        if (null === $this->access->roleOn($assignee, $project)) {
            throw IssueException::notAMember($assignee->getFullName(), $project->getName());
        }

        return $assignee;
    }

    /**
     * The team an issue is given to: one that holds a role on the project,
     * directly or through its workspace, as a person does. The same rule
     * at creation and on reassignment.
     */
    public function assigneeTeam(?int $teamId, Project $project): ?Team
    {
        if (null === $teamId) {
            return null;
        }
        $team = $this->teams->find($teamId) ?? throw IssueException::notFound('Team', $teamId);
        if (!\in_array($team, $this->teams->findOnProject($project), true)) {
            throw IssueException::teamWithoutAccess($team->getName(), $project->getName());
        }

        return $team;
    }

    /**
     * What the issue holds of its fields, whoever looks, read to be
     * changed: the row of the issue is locked first, so that two people
     * filling in the same field at once write one value after the other
     * and never two rows for it.
     *
     * @return array<string, mixed> field id => value
     */
    public function fieldValuesToChange(Issue $issue): array
    {
        $this->entityManager->lock($issue, LockMode::PESSIMISTIC_WRITE);

        return $this->fieldValues->valuesOf($issue);
    }

    /**
     * Writes the values an issue holds of its fields: a row for each, and
     * none for a field emptied.
     *
     * @param array<string, mixed> $changes field id => its value, null or an empty text to empty it
     */
    public function writeFieldValues(Issue $issue, array $changes): void
    {
        $held = $this->fieldValues->rowsOf($issue);
        foreach ($changes as $fieldId => $value) {
            $row = $held[$fieldId] ?? null;
            if (null === $value || '' === $value) {
                if (null !== $row) {
                    $this->entityManager->remove($row);
                }
            } elseif (null !== $row) {
                $row->change($value);
            } else {
                $this->entityManager->persist(new IssueFieldValue($issue, $this->config->row(Field::class, (string) $fieldId), $value));
            }
        }
    }

    /**
     * Every value given is for a field the workflow uses, and an address
     * is one of the web: it is shown as a link, whatever door it came by.
     *
     * @param array<string, mixed> $values
     */
    public function assertKnownFields(WorkflowDefinition $workflow, array $values): void
    {
        $fields = $this->config->definition()->fields;
        foreach ($values as $fieldId => $value) {
            if (!isset($workflow->fields[$fieldId])) {
                throw IssueException::unknownField((string) $fieldId, $workflow->id);
            }
            if (FieldType::Url === ($fields[$fieldId] ?? null)?->type && null !== $value && '' !== $value && (!\is_string($value) || 1 !== preg_match('#^https?://#i', $value))) {
                throw IssueException::notAWebAddress((string) $fieldId);
            }
        }
    }

    /**
     * The required fields of the workflow are filled in the values the
     * issue will hold.
     *
     * @param array<string, mixed> $values
     */
    public function assertRequiredFields(WorkflowDefinition $workflow, array $values): void
    {
        foreach ($workflow->fields as $usage) {
            $value = $values[$usage->field] ?? null;
            if ($usage->required && (null === $value || (\is_string($value) && '' === trim($value)))) {
                throw IssueException::missingField($usage->field);
            }
        }
    }

    /**
     * The comment, if the actor may edit it. A comment the actor may not
     * see is reported as not found, so that private comments cannot be
     * probed; one they see but may not edit is refused.
     */
    public function editableComment(User $actor, int $commentId): Comment
    {
        $comment = $this->comments->find($commentId);
        if (null === $comment || !$this->access->canSeeComment($actor, $comment)) {
            throw IssueException::notFound('Comment', $commentId);
        }
        $this->denyUnless($this->access->canEditComment($actor, $comment), 'edit a comment written by someone else');

        return $comment;
    }

    /**
     * Gives the actor's pending files to what they just wrote: the files
     * sent along (`uuids`) and those its text points to. A file sent by
     * someone else, or already claimed, is left alone.
     *
     * @param list<string> $uuids
     */
    public function claimAttachments(User $actor, Issue $issue, array $uuids, string $text, ?Comment $comment = null): void
    {
        $wanted = Attachment::referencedIn($text);
        foreach ($uuids as $uuid) {
            if (Uuid::isValid($uuid)) {
                $wanted[] = Uuid::fromString($uuid);
            }
        }

        foreach ($this->attachments->findUnattached($issue->getProject(), $actor, $wanted) as $attachment) {
            $attachment->attachTo($issue, $comment);
        }
    }

    public function denyUnless(bool $granted, string $what): void
    {
        if (!$granted) {
            throw new AccessDeniedException(\sprintf('Not allowed to %s.', $what));
        }
    }
}
