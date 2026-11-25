<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Enum\ActivityKind;
use App\Entity\Issue;
use App\Message\Command\Attachment\RemoveAttachment;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\DeleteComment;
use App\Message\Command\Issue\EditComment;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\Message\Query\Issue\IssueDetail;
use App\Message\Query\Issue\ShowIssue;
use App\Message\Query\Issue\TimelineEntry;
use App\Message\QueryBus;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Twig\Components\HandlesAttachments;
use App\Workflow\WorkflowProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Thread of the issue page, then what the viewer may do, in two boxes.
 * The decision lists the workflow actions of the viewer's role, one line
 * each with what it does: an action applies at once, unless it asks for
 * a comment, which is then written in the decision itself and
 * sent with it (kept on screen if the action is refused). The comment box is a comment and nothing else. A success
 * redraws the thread in place, tells the other components of the page
 * (`issue:changed`) and shows a toast; the comment being written survives
 * an action, and what the other components do.
 */
#[AsLiveComponent('Issue:Activity')]
final class Activity
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use HandlesAttachments;

    #[LiveProp]
    public Issue $issue;

    /** The comment being written; not `comment`, which canComment() would read. */
    #[LiveProp(writable: true)]
    public string $body = '';

    #[LiveProp(writable: true)]
    public bool $private = false;

    /** The transition waiting for the comment it requires, null when none does. */
    #[LiveProp]
    public ?string $awaiting = null;

    /** The comment written for that transition. */
    #[LiveProp(writable: true)]
    public string $reason = '';

    /** Why the last action of the workflow did not go through; `error` is the comment box's. */
    public ?string $decisionError = null;

    /** Why the filter of the activity was refused, shown beside it. */
    public ?string $filterError = null;

    /** @var list<string> uuids of the files sent for the comment being written, pending until it is posted */
    #[LiveProp]
    public array $pending = [];

    /** The comment being rewritten, and its new body. */
    #[LiveProp(writable: true)]
    public ?int $editingCommentId = null;

    #[LiveProp(writable: true)]
    public string $commentBody = '';

    /** @var list<string> uuids of the files sent while rewriting a comment */
    #[LiveProp]
    public array $editPending = [];

    /** The comment whose deletion waits for a confirmation. */
    #[LiveProp(writable: true)]
    public ?int $deletingCommentId = null;

    /** The detail, read once per request; forgotten after an action changed the issue. */
    private ?IssueDetail $detail = null;

    public function __construct(
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly QueryBus $queries,
        private readonly TranslatorInterface $translator,
        private readonly AttachmentRepository $attachments,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    /**
     * The files sent for the comment being written, still pending.
     *
     * @return list<Attachment>
     */
    public function getPendingFiles(): array
    {
        return $this->pendingFiles($this->pending);
    }

    /**
     * The files sent while rewriting a comment, still pending.
     *
     * @return list<Attachment>
     */
    public function getEditPendingFiles(): array
    {
        return $this->pendingFiles($this->editPending);
    }

    /**
     * @param list<string> $uuids
     *
     * @return list<Attachment>
     */
    private function pendingFiles(array $uuids): array
    {
        if ([] === $uuids) {
            return [];
        }

        return $this->attachments->findUnattached($this->issue->getProject(), $this->viewer->get(), array_map(Uuid::fromString(...), $uuids));
    }

    public function getDetail(): IssueDetail
    {
        return $this->detail ??= $this->queries->ask(new ShowIssue($this->viewer->get(), (int) $this->issue->getProject()->getId(), $this->issue->getNumber()))
            ?? throw new NotFoundHttpException('The issue is no longer visible.');
    }

    /**
     * The thread without the kinds of activity the viewer hides.
     *
     * @return list<TimelineEntry>
     */
    public function getTimeline(): array
    {
        $hidden = $this->getHiddenKinds();

        return array_values(array_filter(array_map(static fn (TimelineEntry $entry): ?TimelineEntry => $entry->without($hidden), $this->getDetail()->timeline)));
    }

    /**
     * @return list<ActivityKind>
     */
    public function getHiddenKinds(): array
    {
        return $this->viewer->get()->getHiddenActivity();
    }

    /**
     * The kinds of activity the viewer may filter. What a forge reported is
     * private: external users never receive it, so it is not offered.
     *
     * @return list<ActivityKind>
     */
    public function getActivityKinds(): array
    {
        return $this->viewer->get()->isInternal() ? ActivityKind::cases() : [ActivityKind::Changes];
    }

    /**
     * How many moments of the thread each kind holds, before any filter.
     *
     * @return array<string, int>
     */
    public function getActivityCounts(): array
    {
        $timeline = $this->getDetail()->timeline;
        $counts = [];
        foreach ($this->getActivityKinds() as $kind) {
            $counts[$kind->value] = \count(array_filter($timeline, static fn (TimelineEntry $entry): bool => $entry->isOfKind($kind)));
        }

        return $counts;
    }

    /**
     * Shows or hides a kind of activity, for this person on every issue.
     * Filtering is reading, not an activity: no toast, and no other
     * component of the page depends on it.
     */
    #[LiveAction]
    public function toggleActivity(CommandBus $commands, #[LiveArg] string $kind): void
    {
        $kind = ActivityKind::tryFrom($kind);
        if (null === $kind || !\in_array($kind, $this->getActivityKinds(), true)) {
            return;
        }

        $hidden = $this->getHiddenKinds();
        $hidden = \in_array($kind, $hidden, true) ? array_values(array_filter($hidden, static fn (ActivityKind $hiddenKind): bool => $hiddenKind !== $kind)) : [...$hidden, $kind];
        $userId = (int) $this->viewer->get()->getId();
        $this->filterError = $this->refusal($commands, new UpdateUser($userId, hiddenActivity: $hidden, actorId: $userId));
    }

    /**
     * @return list<TransitionDefinition>
     */
    public function getTransitions(): array
    {
        return $this->access->transitionsFor($this->viewer->get(), $this->issue);
    }

    /**
     * The transition waiting for its comment. It is read from the workflow,
     * not from what is offered: if the issue moved in the meantime, what was
     * written stays on screen and the handler says the action is refused.
     */
    public function getAwaitingTransition(): ?TransitionDefinition
    {
        return null === $this->awaiting ? null : $this->workflows->definition($this->issue->getTracker()->getWorkflowId())->transitions[$this->awaiting] ?? null;
    }

    public function canComment(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::CommentCreate, $this->issue->getProject());
    }

    /**
     * The comments of the thread the viewer may edit or delete, with their
     * files: their own, or all of them with `comment.edit`.
     *
     * @return list<int> comment ids
     */
    public function getEditableComments(): array
    {
        $viewer = $this->viewer->get();
        $ids = [];
        foreach ($this->getDetail()->timeline as $entry) {
            if (null !== $entry->comment && $this->access->canEditComment($viewer, $entry->comment)) {
                $ids[] = (int) $entry->comment->getId();
            }
        }

        return $ids;
    }

    public function canMakePrivate(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::CommentSetPrivate, $this->issue->getProject());
    }

    /**
     * Takes an action of the workflow: at once, or, when it asks for a
     * comment, once the comment is written.
     */
    #[LiveAction]
    public function apply(CommandBus $commands, #[LiveArg] string $transition): void
    {
        $chosen = $this->offered($transition);
        if (null === $chosen) {
            $this->decisionError = $this->translator->trans('issue.action.refused');

            return;
        }
        if ($chosen->requiresComment) {
            $this->awaiting = $chosen->id;
            $this->reason = '';

            return;
        }

        $this->transition($commands, $chosen, null);
    }

    /**
     * Applies the action that waited for its comment.
     */
    #[LiveAction]
    public function confirm(CommandBus $commands): void
    {
        $transition = $this->getAwaitingTransition();
        if (null === $transition) {
            $this->cancelDecision();
            $this->decisionError = $this->translator->trans('issue.action.refused');

            return;
        }
        if ('' === trim($this->reason)) {
            // The prompt is already written above the field.
            $this->decisionError = $this->translator->trans('issue.comment.empty');

            return;
        }

        $this->transition($commands, $transition, $this->reason);
    }

    #[LiveAction]
    public function cancelDecision(): void
    {
        $this->awaiting = null;
        $this->reason = '';
    }

    /**
     * Posts the comment being written.
     */
    #[LiveAction]
    public function submit(CommandBus $commands): void
    {
        if (!$this->canComment()) {
            $this->error = $this->translator->trans('issue.action.refused');

            return;
        }
        if ('' === trim($this->body)) {
            $this->error = $this->translator->trans('issue.comment.empty');

            return;
        }

        if (!$this->tryCommand($commands, new AddComment((int) $this->viewer->get()->getId(), $this->issue->getKey(), $this->body, $this->private && $this->canMakePrivate(), $this->pending))) {
            return;
        }

        $this->detail = null;
        $this->body = '';
        $this->private = false;
        $this->pending = [];
        // The editor sits outside the morph (data-live-ignore): it is told to empty itself.
        $this->dispatchBrowserEvent('editor:reset');
        $this->announce($this->translator->trans('issue.comment.added'));
    }

    /**
     * Files chosen for the comment being written, or rewritten: sent now,
     * pending until the comment is posted or saved.
     */
    #[LiveAction]
    public function attach(Request $request, CommandBus $commands): void
    {
        [$sent, $this->error] = $this->sendFiles($request, $commands, $this->viewer->get(), $this->issue->getProject());
        foreach ($sent as $attachment) {
            if (null === $this->editingCommentId) {
                $this->pending[] = $attachment->getUuid()->toRfc4122();
            } else {
                $this->editPending[] = $attachment->getUuid()->toRfc4122();
            }
        }
    }

    /** Takes a file back from the comment being written or rewritten; it stays pending, unused. */
    #[LiveAction]
    public function discard(#[LiveArg] string $uuid): void
    {
        $this->pending = array_values(array_diff($this->pending, [$uuid]));
        $this->editPending = array_values(array_diff($this->editPending, [$uuid]));
    }

    #[LiveAction]
    public function editComment(#[LiveArg] int $id): void
    {
        $comment = $this->editableComment($id);
        if (null === $comment) {
            return;
        }
        $this->editingCommentId = $id;
        $this->commentBody = $comment->getBody();
        $this->editPending = [];
        $this->deletingCommentId = null;
    }

    #[LiveAction]
    public function cancelComment(): void
    {
        $this->editingCommentId = null;
        $this->editPending = [];
    }

    #[LiveAction]
    public function saveComment(CommandBus $commands): void
    {
        if (null === $this->editingCommentId) {
            return;
        }
        if ('' === trim($this->commentBody)) {
            $this->error = $this->translator->trans('issue.comment.empty');

            return;
        }

        if (!$this->tryCommand($commands, new EditComment((int) $this->viewer->get()->getId(), $this->editingCommentId, $this->commentBody, $this->editPending))) {
            return;
        }

        $this->detail = null;
        $this->editingCommentId = null;
        $this->editPending = [];
        $this->announce($this->translator->trans('issue.comment.updated'));
    }

    #[LiveAction]
    public function confirmDelete(#[LiveArg] int $id): void
    {
        $this->deletingCommentId = null === $this->editableComment($id) ? null : $id;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->deletingCommentId = null;
    }

    #[LiveAction]
    public function deleteComment(CommandBus $commands): void
    {
        if (null === $this->deletingCommentId) {
            return;
        }

        if (!$this->tryCommand($commands, new DeleteComment((int) $this->viewer->get()->getId(), $this->deletingCommentId))) {
            return;
        }

        $this->detail = null;
        if ($this->editingCommentId === $this->deletingCommentId) {
            $this->cancelComment();
        }
        $this->deletingCommentId = null;
        $this->announce($this->translator->trans('issue.comment.deleted'));
    }

    /** Removes a file of a posted comment. */
    #[LiveAction]
    public function removeAttachment(CommandBus $commands, #[LiveArg] string $uuid, #[LiveArg] string $name): void
    {
        if ($this->tryCommand($commands, new RemoveAttachment((int) $this->viewer->get()->getId(), $uuid))) {
            $this->detail = null;
            $this->announce($this->translator->trans('attachment.removed', ['%name%' => $name]));
        }
    }

    /**
     * Redraws with the issue as another component left it; the comment
     * being written is kept.
     */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    /** A comment of the thread the viewer may edit, null otherwise. */
    private function editableComment(int $id): ?Comment
    {
        foreach ($this->getDetail()->timeline as $entry) {
            if ($entry->comment?->getId() === $id && $this->access->canEditComment($this->viewer->get(), $entry->comment)) {
                return $entry->comment;
            }
        }

        return null;
    }

    /** The transition of that id, if it is one the viewer may apply from where the issue stands. */
    private function offered(string $id): ?TransitionDefinition
    {
        return array_find($this->getTransitions(), static fn (TransitionDefinition $transition): bool => $transition->id === $id);
    }

    private function transition(CommandBus $commands, TransitionDefinition $transition, ?string $comment): void
    {
        $this->decisionError = $this->refusal($commands, new ApplyTransition((int) $this->viewer->get()->getId(), $this->issue->getKey(), $transition->id, $comment));
        if (null !== $this->decisionError) {
            return;
        }

        $this->detail = null;
        $this->cancelDecision();
        $this->announce($this->translator->trans('issue.transition.applied', ['%key%' => $this->issue->getKey(), '%transition%' => $transition->label]));
    }
}
