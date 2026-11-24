<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Comment;
use App\Entity\Enum\ActivityKind;
use App\Entity\Enum\JournalEntryType;
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
     * activity, null when nothing is. A comment that came with an action
     * follows the comments, the entry follows its own kind: hiding the
     * changes still leaves the comment of a refusal to read.
     *
     * @param list<ActivityKind> $hidden
     */
    public function without(array $hidden): ?self
    {
        $event = null !== $this->event && !\in_array($this->kindOfEvent($this->event), $hidden, true) ? $this->event : null;
        $comment = !\in_array(ActivityKind::Comments, $hidden, true) ? $this->comment : null;

        return null === $event && null === $comment ? null : new self($this->at, $event, $comment);
    }

    private function kindOfEvent(JournalEntry $event): ActivityKind
    {
        return match (true) {
            $event->getType()->isCode() => ActivityKind::Code,
            \in_array($event->getType(), [JournalEntryType::CommentEdited, JournalEntryType::CommentDeleted], true) => ActivityKind::Comments,
            default => ActivityKind::Changes,
        };
    }
}
