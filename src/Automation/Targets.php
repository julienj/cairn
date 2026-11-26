<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\Target;
use App\Config\ConfigRegistry;
use App\Entity\Issue;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\JournalEntryRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Security\Referents;

/**
 * Turns the target a rule names into a person, on one issue: the referent
 * of a role on its project, its author, the last internal user
 * who wrote on it, or a named member; or into the team it names. When
 * there is nobody behind the target, it says why, which is what the
 * execution of the rule reports: `no_referent`,
 * `no_internal`, `unknown_user`, `unknown_team`, `deactivated`.
 */
final class Targets
{
    public function __construct(
        private readonly Referents $referents,
        private readonly JournalEntryRepository $journal,
        private readonly CommentRepository $comments,
        private readonly UserRepository $users,
        private readonly TeamRepository $teams,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function resolve(string $target, Issue $issue): ResolvedTarget
    {
        $parsed = Target::parse($target);
        if (Target::NOBODY === $parsed?->kind) {
            return ResolvedTarget::nobody();
        }
        if (Target::TEAM === $parsed?->kind) {
            $team = $this->teams->find((int) $parsed->argument);

            return null === $team ? ResolvedTarget::missing('unknown_team', '') : ResolvedTarget::toTeam($team);
        }
        [$user, $missing] = match ($parsed?->kind) {
            Target::REFERENT => [$this->referents->of($issue->getProject(), $parsed->argument), 'no_referent'],
            Target::AUTHOR => [$issue->getAuthor(), 'unknown_user'],
            Target::LAST_INTERNAL => [$this->lastInternal($issue), 'no_internal'],
            Target::USER => [$this->users->find((int) $parsed->argument), 'unknown_user'],
            default => [null, 'unknown_user'],
        };
        $label = Target::REFERENT === $parsed?->kind ? $this->roleLabel($parsed->argument) : (string) $user?->getFullName();

        return match (true) {
            null === $user => ResolvedTarget::missing($missing, $label),
            !$user->isActive() => ResolvedTarget::missing('deactivated', $user->getFullName()),
            default => ResolvedTarget::to($user, $label),
        };
    }

    private function lastInternal(Issue $issue): ?User
    {
        if (null === $issue->getId()) {
            return $issue->getAuthor()->isInternal() ? $issue->getAuthor() : null;
        }
        $entry = $this->journal->findLastByInternal($issue);
        $comment = $this->comments->findLastByInternal($issue);
        if (null !== $comment && (null === $entry || $comment->getCreatedAt() >= $entry->getAt())) {
            return $comment->getAuthor();
        }

        return $entry?->getAuthor();
    }

    private function roleLabel(string $role): string
    {
        return $this->config->definition()->role($role)->label;
    }
}
