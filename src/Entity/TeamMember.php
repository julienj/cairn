<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamMemberRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Someone is part of a team. Current state, like a membership: leaving
 * the team deletes the row. An internal team takes internal users only.
 */
#[ORM\Entity(repositoryClass: TeamMemberRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_team_member', columns: ['team_id', 'user_id'])]
#[ORM\Index(name: 'idx_team_member_user', columns: ['user_id'])]
class TeamMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Team $team;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    public function __construct(Team $team, User $user)
    {
        if ($team->isInternal() && !$user->isInternal()) {
            throw new \InvalidArgumentException('An internal team takes internal users only.');
        }
        $this->team = $team;
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
