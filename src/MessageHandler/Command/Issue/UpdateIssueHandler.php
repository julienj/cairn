<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Config\ConfigRegistry;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Release;
use App\Entity\User;
use App\Event\IssueUpdated;
use App\Message\Command\Issue\UpdateIssue;
use App\Repository\ReleaseRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every right is checked before anything changes, so that a refused
 * property leaves the others untouched. The content (title, description,
 * fields) follows AccessControl::canEditIssue(), the other properties
 * their own permission. A property that already holds the value is left
 * alone and not journaled; the others get one journal entry each, with
 * the values of the time (private for an internal-only field).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly ReleaseRepository $releases,
    ) {
    }

    public function __invoke(UpdateIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $project = $issue->getProject();

        if (null !== $command->title || null !== $command->description || null !== $command->fields) {
            $this->support->denyUnless($this->access->canEditIssue($actor, $issue), 'edit an issue written by someone else');
        }
        $held = null !== $command->fields ? $this->support->fieldValuesToChange($issue) : [];
        $fieldChanges = null !== $command->fields ? $this->fieldChanges($actor, $issue, $command->fields, $held) : [];
        $assignee = null;
        if (null !== $command->assigneeId) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueAssign, $project), 'assign issues');
            $assignee = $this->support->assignee(UpdateIssue::NOBODY === $command->assigneeId ? null : $command->assigneeId, $project);
        }
        $assigneeTeam = null;
        if (null !== $command->assigneeTeamId) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueAssign, $project), 'assign issues');
            $assigneeTeam = $this->support->assigneeTeam($command->assigneeTeamId, $project);
        }
        if (null !== $command->criticality) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetCriticality, $project), 'set the criticality');
        }
        if (null !== $command->private) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetPrivate, $project), 'change the visibility');
            if (!$command->private && $issue->getTracker()->isPrivate()) {
                throw IssueException::privateTracker($issue->getTracker()->getName());
            }
        }
        $release = null;
        if (null !== $command->releaseId) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetRelease, $project), 'schedule issues in releases');
            $release = UpdateIssue::NOBODY === $command->releaseId ? null : $this->release($issue, $command->releaseId);
        }

        $entries = [];
        $newAssignee = null;
        $newAssigneeTeam = null;
        $descriptionChanged = false;
        if (null !== $command->title && $issue->getTitle() !== trim($command->title)) {
            $previous = $issue->getTitle();
            $issue->retitle($command->title);
            $entries[] = JournalEntry::changed($issue, $actor, 'title', $previous, $issue->getTitle());
        }
        if (null !== $command->description && $issue->getDescription() !== $command->description) {
            $previous = $issue->getDescription();
            $issue->describe($command->description);
            $entries[] = JournalEntry::changed($issue, $actor, 'description', $previous, $issue->getDescription());
            $descriptionChanged = true;
            // Images pasted while rewriting it were uploaded pending.
            $this->support->claimAttachments($actor, $issue, [], $command->description);
        }
        if ([] !== $fieldChanges) {
            $this->support->writeFieldValues($issue, $fieldChanges);
            foreach ($fieldChanges as $fieldId => $value) {
                $entries[] = JournalEntry::fieldChanged($issue, $actor, $this->config->definition()->fields[$fieldId], $held[$fieldId] ?? null, $value);
            }
        }
        // A person or a team, one or the other: giving the issue to one takes it from the other, and to nobody from both.
        if (null !== $command->assigneeId && ($issue->getAssignee() !== $assignee || null !== $issue->getAssigneeTeam())) {
            $previous = $issue->getAssigneeName();
            $issue->assignTo($assignee);
            $entries[] = JournalEntry::changed($issue, $actor, 'assignee', $previous, $issue->getAssigneeName());
            $newAssignee = $assignee;
        }
        if (null !== $assigneeTeam && $issue->getAssigneeTeam() !== $assigneeTeam) {
            $previous = $issue->getAssigneeName();
            $issue->assignToTeam($assigneeTeam);
            $entries[] = JournalEntry::changed($issue, $actor, 'assignee', $previous, $issue->getAssigneeName());
            $newAssigneeTeam = $assigneeTeam;
        }
        if (null !== $command->criticality && $issue->getCriticality() !== $command->criticality) {
            $previous = $issue->getCriticality();
            $issue->setCriticality($command->criticality);
            $entries[] = JournalEntry::changed($issue, $actor, 'criticality', $previous->value, $command->criticality->value);
        }
        if (null !== $command->releaseId && $issue->getRelease() !== $release) {
            $previous = $issue->getRelease();
            $issue->scheduleIn($release);
            $entries[] = JournalEntry::changed($issue, $actor, 'release', $previous?->getName(), $release?->getName());
        }
        if (null !== $command->private && $issue->isPrivate() !== $command->private) {
            $command->private ? $issue->makePrivate() : $issue->makePublic();
            $entries[] = JournalEntry::changed($issue, $actor, 'visibility', $command->private ? 'public' : 'private', $command->private ? 'private' : 'public');
        }

        // Nothing changed, nothing to tell.
        if ([] !== $entries) {
            $this->support->record(new IssueUpdated($issue, $actor, $entries, $newAssignee, $descriptionChanged, newAssigneeTeam: $newAssigneeTeam));
        }
    }

    /**
     * The values that actually change, merged into what the issue holds:
     * the fields not listed keep their value, the values of fields the
     * workflow left are kept and ignored. Required fields must stay
     * filled, internal-only fields are for internal users.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $current what the issue holds, by field
     *
     * @return array<string, mixed> field id => new value, null when emptied
     */
    private function fieldChanges(User $actor, Issue $issue, array $values, array $current): array
    {
        $definition = $this->config->definition();
        $workflow = $definition->workflow($issue->getTracker()->getWorkflowId());
        $this->support->assertKnownFields($workflow, $values);

        $changes = [];
        foreach ($values as $fieldId => $value) {
            $value = '' === $value ? null : $value;
            if (($current[$fieldId] ?? null) !== $value) {
                $changes[$fieldId] = $value;
            }
        }

        $this->support->assertRequiredFields($workflow, array_filter(array_replace($current, $changes), static fn (mixed $value): bool => null !== $value));
        foreach (array_keys($changes) as $fieldId) {
            $this->support->denyUnless($actor->isInternal() || !$definition->fields[$fieldId]->internalOnly, 'edit internal fields');
        }

        return $changes;
    }

    /**
     * A release of the issue's project that still takes issues; the one
     * it is already in is accepted as it is, so that sending it back
     * unchanged refuses nothing.
     */
    private function release(Issue $issue, int $releaseId): Release
    {
        $release = $this->releases->find($releaseId) ?? throw IssueException::notFound('Release', $releaseId);
        if ($release->getProject() !== $issue->getProject()) {
            throw IssueException::releaseOutsideProject($release->getName(), $issue->getProject()->getName());
        }
        if (!$release->isOpen() && $issue->getRelease() !== $release) {
            throw IssueException::closedRelease($release->getName());
        }

        return $release;
    }
}
