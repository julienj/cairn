<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Enum\JournalEntryType;
use App\Entity\JournalEntry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * The sentence of a journal entry, without its subject: "created the
 * issue", "applied "Accept": To test → Done". The entry keeps ids and
 * labels of the time; this is the only place that reads what each type
 * recorded, for the thread of the issue page (Issue:JournalEntry), which
 * draws its values one by one (`parts()`), and for the mails, which read
 * it as a sentence (`label()`). Values that are ours are translated (criticality,
 * visibility, "nobody", no release).
 */
final class JournalLabelExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AsTwigFilter('journal_label')]
    public function label(JournalEntry $entry): string
    {
        [$key, $parameters] = $this->message($entry);

        return $this->translator->trans($key, array_map(static fn (array $value): string => $value[1], $parameters));
    }

    /**
     * The same sentence, cut where its values stand, so that a page draws
     * each as what it is: a step, what a value was and what it became, a
     * file, the key of another issue. The words between them are the
     * translator's, untouched.
     *
     * @return list<array{kind: string, text: string}> kind: text, place, old, value, file, issue or quiet
     */
    public function parts(JournalEntry $entry): array
    {
        [$key, $parameters] = $this->message($entry);
        $marks = [];
        $values = [];
        foreach ($parameters as $name => $value) {
            // a count chooses the plural: it stays a number
            if ('%count%' === $name) {
                $marks[$name] = $value[1];
                continue;
            }
            $marks[$name] = "\x02".\count($values)."\x03";
            $values[] = $value;
        }

        $parts = [];
        foreach (preg_split('/(\x02\d+\x03)/', $this->translator->trans($key, $marks), -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if ("\x02" !== $piece[0]) {
                $parts[] = ['kind' => 'text', 'text' => $piece];
                continue;
            }
            [$kind, $text] = $values[(int) substr($piece, 1, -1)];
            if ('' !== $text) {
                $parts[] = ['kind' => $kind, 'text' => $text];
            }
        }

        return $parts;
    }

    /**
     * The message of an entry and its values, each with what it is.
     *
     * @return array{string, array<string, array{string, string}>}
     */
    private function message(JournalEntry $entry): array
    {
        $data = $entry->getData();
        $type = $entry->getType();

        return match ($type) {
            JournalEntryType::Created => ['issue.event.created', []],
            JournalEntryType::Transitioned => ['issue.event.transitioned', ['%transition%' => ['text', $entry->text('transition')], '%from%' => ['place', $entry->text('from')], '%to%' => ['place', $entry->text('to')]]],
            JournalEntryType::TrackerChanged => ['issue.event.tracker_changed', ['%from_tracker%' => ['old', $entry->text('from_tracker')], '%to_tracker%' => ['value', $entry->text('to_tracker')], '%to%' => ['place', $entry->text('to')]]],
            JournalEntryType::Moved => ['issue.event.moved', ['%from%' => ['place', $entry->text('from')], '%to%' => ['place', $entry->text('to')]]],
            JournalEntryType::FieldChanged => ['issue.event.field_changed', ['%field%' => ['text', $entry->text('label')], '%from%' => ['old', $entry->text('from', '—')], '%to%' => ['value', $entry->text('to', '—')]]],
            JournalEntryType::Changed => $this->changedMessage($entry),
            JournalEntryType::CommentEdited, JournalEntryType::CommentDeleted => [
                \sprintf('issue.event.%s.%s', $type->value, ($data['author_id'] ?? null) === $entry->getAuthor()?->getId() ? 'own' : 'other'),
                ['%author%' => ['text', $entry->text('author')]],
            ],
            JournalEntryType::Linked, JournalEntryType::Unlinked => ['issue.event.'.$type->value, ['%label%' => ['text', $entry->text('label')], '%key%' => ['issue', $entry->text('key')], '%title%' => ['quiet', $entry->text('title')]]],
            JournalEntryType::AttachmentAdded, JournalEntryType::AttachmentRemoved => ['issue.event.'.$type->value, ['%filename%' => ['file', $entry->text('filename')]]],
            JournalEntryType::CommitsPushed => ['issue.event.commits_pushed', ['%count%' => ['text', (string) \count((array) ($data['commits'] ?? []))], '%branch%' => ['text', $entry->text('branch')], '%repository%' => ['text', $entry->text('repository')]]],
            JournalEntryType::MergeRequestOpened, JournalEntryType::MergeRequestMerged, JournalEntryType::MergeRequestClosed, JournalEntryType::MergeRequestReopened => ['issue.event.'.$type->value, ['%number%' => ['text', $entry->text('number')], '%title%' => ['text', $entry->text('title')], '%repository%' => ['text', $entry->text('repository')]]],
            JournalEntryType::Imported => ['issue.event.imported', ['%source%' => ['text', $entry->text('source')], '%ref%' => ['text', $entry->text('ref')]]],
        };
    }

    /**
     * @return array{string, array<string, array{string, string}>}
     */
    private function changedMessage(JournalEntry $entry): array
    {
        $data = $entry->getData();
        $field = $entry->text('field');
        if ('description' === $field) {
            return ['issue.event.changed.description', []];
        }

        $shown = fn (string $key): string => match (true) {
            !isset($data[$key]) => $this->translator->trans('release' === $field ? 'issue.show.no_release' : 'issue.show.nobody'),
            'criticality' === $field => $this->translator->trans('criticality.'.$entry->text($key)),
            'visibility' === $field => $this->translator->trans('issue.show.'.('private' === $data[$key] ? 'hidden' : 'visible').'_externals'),
            default => $entry->text($key),
        };

        return ['issue.event.changed.'.$field, ['%from%' => ['old', $shown('from')], '%to%' => ['value', $shown('to')]]];
    }
}
