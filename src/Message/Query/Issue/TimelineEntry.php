<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Comment;
use App\Entity\Enum\ActivityKind;
use App\Entity\JournalEntry;

/**
 * One moment of the thread of an issue: a journal entry, a comment, or a
 * journal entry with the comment that came with it. `comment` is the one
 * shown at that moment, whichever way it got there.
 */
final readonly class TimelineEntry
{
    private function __construct(
        public \DateTimeImmutable $at,
        public ?JournalEntry $event,
        public ?Comment $comment,
    ) {
    }

    public static function ofEvent(JournalEntry $event): self
    {
        return new self($event->getAt(), $event, $event->getComment());
    }

    public static function ofComment(Comment $comment): self
    {
        return new self($comment->getCreatedAt(), null, $comment);
    }

    /**
     * What is left of this moment once the viewer hid some kinds of
     * activity, null when nothing is. Comments are never hidden, so a
     * comment that came with an action stays to read when the action goes.
     *
     * @param list<ActivityKind> $hidden
     */
    public function without(array $hidden): ?self
    {
        $event = null !== $this->event && !\in_array($this->kindOfEvent($this->event), $hidden, true) ? $this->event : null;

        return null === $event && null === $this->comment ? null : new self($this->at, $event, $this->comment);
    }

    private function kindOfEvent(JournalEntry $event): ActivityKind
    {
        return $event->getType()->isCode() ? ActivityKind::Code : ActivityKind::Changes;
    }

    /** Whether the moment is of this kind: a comment alone is of none. */
    public function isOfKind(ActivityKind $kind): bool
    {
        return null !== $this->event && $this->kindOfEvent($this->event) === $kind;
    }
}
