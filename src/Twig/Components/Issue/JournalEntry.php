<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry as Entry;
use App\Repository\IssueRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use App\Twig\JournalLabelExtension;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * One line of the history of an issue: who did what, when. The sentence
 * comes from Twig/JournalLabelExtension, cut where its values stand, the
 * texts a rewriting or a deletion kept are readable behind a disclosure.
 * What a forge wrote (commits pushed, a merge request) is a card instead
 * (Ui:CodeCard), read by the template from the entry's data.
 */
#[AsTwigComponent('Issue:JournalEntry')]
final class JournalEntry
{
    public Entry $entry;

    public function __construct(
        private readonly JournalLabelExtension $labels,
        private readonly IssueRepository $issues,
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
    ) {
    }

    /**
     * The sentence cut where its values stand, each drawn as what it is.
     *
     * @return list<array{kind: string, text: string}>
     */
    public function getParts(): array
    {
        return $this->labels->parts($this->entry);
    }

    /** The icon that tells, on the line of the thread, what kind of change this was. */
    public function getIcon(): string
    {
        return match ($this->entry->getType()) {
            JournalEntryType::Created => 'plus',
            JournalEntryType::Transitioned, JournalEntryType::Moved => 'arrow-right',
            JournalEntryType::TrackerChanged => 'tag',
            JournalEntryType::FieldChanged => 'sliders-horizontal',
            JournalEntryType::Changed => match ($this->entry->text('field')) {
                'assignee' => 'user',
                'release' => 'milestone',
                'criticality' => 'flame',
                'visibility' => 'lock',
                'tag_added', 'tag_removed' => 'tag',
                default => 'pencil',
            },
            JournalEntryType::AttachmentAdded, JournalEntryType::AttachmentRemoved => 'paperclip',
            JournalEntryType::CommentEdited => 'pencil',
            JournalEntryType::CommentDeleted => 'trash-2',
            JournalEntryType::Linked, JournalEntryType::Unlinked => 'link',
            JournalEntryType::Imported => 'archive-restore',
            default => 'history',
        };
    }

    /**
     * The issue a link names, when the reader may see it: the journal keeps
     * its key, which says nothing of who may follow it.
     */
    public function getLinkedIssue(): ?Issue
    {
        if (!\in_array($this->entry->getType(), [JournalEntryType::Linked, JournalEntryType::Unlinked], true)) {
            return null;
        }
        $issue = $this->issues->findOneByKey($this->entry->text('key'));

        return null !== $issue && $this->access->canSee($this->viewer->get(), $issue) ? $issue : null;
    }

    /**
     * The text before and after, when the entry recorded a rewriting (a
     * description, a comment).
     *
     * @return array{from: string, to: string}|null
     */
    public function getVersions(): ?array
    {
        $data = $this->entry->getData();
        $rewrote = match ($this->entry->getType()) {
            JournalEntryType::CommentEdited => true,
            JournalEntryType::Changed => 'description' === ($data['field'] ?? null) && null !== ($data['from'] ?? null),
            default => false,
        };

        return $rewrote ? ['from' => $this->text($data, 'from'), 'to' => $this->text($data, 'to')] : null;
    }

    /** The text of a deleted comment, which lives on in its entry. */
    public function getDeletedBody(): ?string
    {
        return JournalEntryType::CommentDeleted === $this->entry->getType() ? $this->text($this->entry->getData(), 'body') : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key, string $default = ''): string
    {
        return $this->entry->text($key, $default);
    }
}
