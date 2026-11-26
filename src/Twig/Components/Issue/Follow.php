<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Issue;
use App\Entity\Team;
use App\Message\Command\Issue\FollowIssue;
use App\Message\Command\Issue\UnfollowIssue;
use App\Message\CommandBus;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The "Follow" / "Unfollow" button at the top of the issue page: the
 * only setting of the notifications, for whoever holds a role on the
 * project. Someone written to through a team reads that instead. Redrawn on `issue:changed`, since a
 * comment or an assignment may have subscribed the viewer.
 */
#[AsLiveComponent('Issue:Follow')]
final class Follow
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use GuardsIssue;

    #[LiveProp]
    public Issue $issue;

    public function __construct(
        private readonly IssueSubscriptionRepository $subscriptions,
        private readonly IssueTeamSubscriptionRepository $teamSubscriptions,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function isFollowing(): bool
    {
        return null !== $this->subscriptions->findOne($this->issue, $this->viewer->get());
    }

    /**
     * Whether the viewer may follow the issue: it takes a role on its
     * project (AccessControl::canFollow()). Without one, nothing is offered.
     */
    public function canFollow(): bool
    {
        return $this->issueAccess->canFollow($this->viewer->get(), $this->issue);
    }

    /**
     * The teams through which the viewer is written to without following
     * the issue themselves, by name: the button says so instead of
     * offering what they already have. A member stops their team
     * following from the card of the followers.
     *
     * @return list<string>
     */
    public function getTeams(): array
    {
        return array_map(static fn (Team $team): string => $team->getName(), $this->teamSubscriptions->findTeamsOf($this->issue, $this->viewer->get()));
    }

    #[LiveAction]
    public function follow(CommandBus $commands): void
    {
        if ($this->tryCommand($commands, new FollowIssue((int) $this->viewer->get()->getId(), $this->issue->getKey()))) {
            $this->announce($this->translator->trans('issue.follow.followed'));
        }
    }

    #[LiveAction]
    public function unfollow(CommandBus $commands): void
    {
        if ($this->tryCommand($commands, new UnfollowIssue((int) $this->viewer->get()->getId(), $this->issue->getKey()))) {
            $this->announce($this->translator->trans('issue.follow.unfollowed'));
        }
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }
}
