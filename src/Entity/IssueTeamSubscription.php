<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueTeamSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A team follows an issue: whoever is part of it when something happens
 * receives the mail (NotifyIssueChangeHandler), so nobody is subscribed
 * for the team and its members come and go without a row to keep in
 * step. Made by Notification/SubscriptionRules when the issue is given to
 * the team or a text mentions it, or by an automation rule; current
 * state, like the subscription of a person: a member unsubscribing the
 * team deletes the row, and the next of those subscribes it again. The
 * team follows only while it holds a role on the project of the issue,
 * asked each time the row is read (IssueTeamSubscriptionRepository).
 */
#[ORM\Entity(repositoryClass: IssueTeamSubscriptionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_issue_team_subscription', columns: ['issue_id', 'team_id'])]
class IssueTeamSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Team $team;

    #[ORM\Column]
    private \DateTimeImmutable $since;

    public function __construct(Issue $issue, Team $team)
    {
        $this->issue = $issue;
        $this->team = $team;
        $this->since = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getSince(): \DateTimeImmutable
    {
        return $this->since;
    }
}
