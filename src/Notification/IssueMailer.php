<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Project;
use App\Entity\User;
use App\Markdown\MarkdownRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds and sends the mail that tells one subscriber what happened on a
 * project: one card per issue, with the journal lines of each command,
 * the comment that came with it, and the description of an issue created
 * in the meantime so that it reads on its own. One format, whether one
 * issue changed or ten; what the recipient may not see was left out by
 * the caller, and the texts are rendered for them, so that an issue
 * reference they may not see stays text, with absolute URLs. The subject
 * names the issues, so that a mailbox can still be searched by key.
 */
final class IssueMailer
{
    /** Keys named in the subject before "and N others". */
    private const int SUBJECT_KEYS = 3;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly MarkdownRenderer $markdown,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param non-empty-list<IssueChanges> $issues what the recipient may read, in the order of the mail
     */
    public function send(User $recipient, Project $project, array $issues): void
    {
        $cards = [];
        foreach ($issues as $card) {
            $issue = $card->issue;
            $url = $this->urls->generate('app_issue_show', [
                'workspaceKey' => $project->getWorkspace()->getKey(),
                'projectKey' => $project->getKey(),
                'number' => $issue->getNumber(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);
            $changes = [];
            foreach ($card->changes as $change) {
                $changes[] = [
                    'change' => $change,
                    'commentHtml' => null === $change->comment ? null : $this->markdown->render($change->comment->getBody(), $recipient, $project, absoluteUrls: true),
                    // The address of the comment on the page of the issue (Issue:Activity), as on GitHub.
                    'commentUrl' => null === $change->comment ? null : \sprintf('%s#comment-%d', $url, $change->comment->getId()),
                ];
            }
            $cards[] = [
                'issue' => $issue,
                'url' => $url,
                'changes' => $changes,
                'descriptionHtml' => $card->created() && '' !== trim($issue->getDescription()) ? $this->markdown->render($issue->getDescription(), $recipient, $project, absoluteUrls: true) : null,
            ];
        }

        $email = (new TemplatedEmail())
            ->to(new Address($recipient->getEmail(), $recipient->getFullName()))
            ->subject($this->subject($project, $issues))
            ->htmlTemplate('email/project_changed.html.twig')
            ->context([
                'recipient' => $recipient,
                'project' => $project,
                'cards' => $cards,
            ]);

        $this->mailer->send($email);
    }

    /**
     * @param non-empty-list<IssueChanges> $issues
     */
    private function subject(Project $project, array $issues): string
    {
        if (1 === \count($issues)) {
            return $this->translator->trans('email.issue.subject.one', ['%workspace%' => $project->getWorkspace()->getName(), '%project%' => $project->getName(), '%key%' => $issues[0]->issue->getKey(), '%title%' => $issues[0]->issue->getTitle()]);
        }

        $keys = array_map(static fn (IssueChanges $card): string => $card->issue->getKey(), $issues);
        $others = \count($keys) - self::SUBJECT_KEYS;
        $parameters = ['%workspace%' => $project->getWorkspace()->getName(), '%project%' => $project->getName(), '%keys%' => implode(', ', \array_slice($keys, 0, self::SUBJECT_KEYS))];

        return $others > 0
            ? $this->translator->trans('email.issue.subject.more', $parameters + ['%count%' => $others])
            : $this->translator->trans('email.issue.subject.many', $parameters);
    }
}
