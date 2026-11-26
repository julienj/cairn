<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\IssueTag;
use App\Entity\JournalEntry;
use App\Event\IssueCreated;
use App\Message\Command\Issue\CreateIssue;
use App\MessageHandler\Command\Tag\TagException;
use App\Repository\ProjectRepository;
use App\Repository\TagRepository;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly ProjectRepository $projects,
        private readonly TrackerRepository $trackers,
        private readonly TagRepository $tags,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateIssue $command): Issue
    {
        $actor = $this->support->actor($command->actorId);
        $project = $this->projects->find($command->projectId) ?? throw IssueException::notFound('Project', $command->projectId);
        $tracker = $this->trackers->find($command->trackerId) ?? throw IssueException::notFound('Tracker', $command->trackerId);

        // Refused by the permission check too; this one names the reason.
        if ($project->isReadOnly()) {
            throw IssueException::archivedProject($project->getName());
        }
        if ($tracker->getProject()->getId() !== $project->getId()) {
            throw IssueException::trackerOutsideProject($tracker->getName(), $project->getName());
        }
        if ($tracker->isArchived()) {
            throw IssueException::archivedTracker($tracker->getName());
        }

        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueCreate, $project), 'create issues in this project');
        $this->support->denyUnless($actor->isInternal() || !$tracker->isPrivate(), 'use this tracker');
        $this->support->denyUnless((null === $command->assigneeId && null === $command->assigneeTeamId) || $this->access->isGranted($actor, Permission::IssueAssign, $project), 'assign issues');
        $this->support->denyUnless(!$command->private || $this->access->isGranted($actor, Permission::IssueSetPrivate, $project), 'make issues private');
        $this->support->denyUnless(Criticality::DEFAULT === $command->criticality || $this->access->isGranted($actor, Permission::IssueSetCriticality, $project), 'set the criticality');
        $this->support->denyUnless([] === $command->tagIds || $this->access->isGranted($actor, Permission::IssueSetTags, $project), 'tag issues');

        $workflow = $this->workflows->definition($tracker->getWorkflowId());
        $this->support->assertKnownFields($workflow, $command->fields);
        $this->support->assertRequiredFields($workflow, $command->fields);

        $assignee = $this->support->assignee($command->assigneeId, $project);
        $assigneeTeam = $this->support->assigneeTeam($command->assigneeTeamId, $project);
        $tags = [];
        foreach (array_unique($command->tagIds) as $tagId) {
            $tag = $this->tags->find($tagId) ?? throw TagException::notFound('Tag', $tagId);
            if ($tag->getProject() !== $project) {
                throw TagException::otherProject($tag->getName(), $project->getIssuePrefix());
            }
            $tags[] = $tag;
        }

        // Lock the project row so that concurrent creations draw distinct numbers.
        $this->entityManager->lock($project, LockMode::PESSIMISTIC_WRITE);

        $issue = new Issue($project, $tracker, $this->workflows->row($workflow->initial), $project->allocateIssueNumber(), $actor, $command->title, $command->description);
        $issue->setCriticality($command->criticality);
        null !== $assigneeTeam ? $issue->assignToTeam($assigneeTeam) : $issue->assignTo($assignee);
        if ($command->private || $tracker->isPrivate()) {
            $issue->makePrivate();
        }

        $this->support->arrive($issue);
        $this->entityManager->persist($issue);
        $this->support->writeFieldValues($issue, $command->fields);
        foreach ($tags as $tag) {
            $this->entityManager->persist(new IssueTag($issue, $tag));
        }

        // Files sent while writing the issue were uploaded before it existed.
        $this->support->claimAttachments($actor, $issue, $command->attachments, $command->description);
        $this->support->record(new IssueCreated($issue, $actor, [JournalEntry::created($issue, $actor)]));

        return $issue;
    }
}
