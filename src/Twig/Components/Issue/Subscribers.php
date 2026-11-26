<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Issue;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Message\Command\Issue\UnfollowIssueAsTeam;
use App\Message\CommandBus;
use App\Message\Query\Issue\ListSubscribers;
use App\Message\QueryBus;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\TeamMemberRepository;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Who follows the issue, a card of its side panel: the teams, each as
 * one with how many people it counts, then the people a change will be
 * written to. A member of a team stops it following the issue, for the
 * whole team. Redrawn on `issue:changed`.
 */
#[AsLiveComponent('Issue:Subscribers')]
final class Subscribers
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use GuardsIssue;

    #[LiveProp]
    public Issue $issue;

    /** @var list<User>|null read once per render */
    private ?array $subscribers = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly IssueTeamSubscriptionRepository $teamSubscriptions,
        private readonly TeamMemberRepository $teamMembers,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<User>
     */
    public function getSubscribers(): array
    {
        return $this->subscribers ??= $this->queries->ask(new ListSubscribers($this->issue));
    }

    /**
     * The teams following the issue, each with how many active members a
     * change is written to and whether the viewer, part of it, may stop
     * it following.
     *
     * @return list<array{team: Team, size: int, mine: bool}>
     */
    public function getTeams(): array
    {
        $viewer = $this->viewer->get();
        $teams = [];
        foreach ($this->teamSubscriptions->findTeams($this->issue) as $team) {
            $members = array_map(static fn (TeamMember $member): User => $member->getUser(), $this->teamMembers->findOfTeam($team));
            $teams[] = [
                'team' => $team,
                'size' => \count(array_filter($members, static fn (User $member): bool => $member->isActive())),
                'mine' => \in_array($viewer, $members, true),
            ];
        }

        return $teams;
    }

    #[LiveAction]
    public function unfollowTeam(CommandBus $commands, #[LiveArg] int $team): void
    {
        if ($this->tryCommand($commands, new UnfollowIssueAsTeam((int) $this->viewer->get()->getId(), $this->issue->getKey(), $team))) {
            $this->announce($this->translator->trans('issue.subscribers.team_unfollowed'));
        }
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
        $this->subscribers = null;
    }
}
