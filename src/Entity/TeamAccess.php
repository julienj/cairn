<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamAccessRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A role granted to a team on a workspace or on a project, exactly one of
 * the two: every member of the team holds it there, as if it had been
 * granted to each (see AccessControl). The role is an id of the instance
 * configuration. An access on a workspace applies to every project of
 * the workspace; one on a project may only widen it.
 */
#[ORM\Entity(repositoryClass: TeamAccessRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_team_access_workspace', columns: ['team_id', 'workspace_id'])]
#[ORM\UniqueConstraint(name: 'uniq_team_access_project', columns: ['team_id', 'project_id'])]
class TeamAccess implements RoleGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Team $team;

    #[ORM\ManyToOne]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne]
    private ?Project $project = null;

    /** The role, an element of the configuration: only its key is read from here, what it says comes from Config\ConfigRegistry. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'role', nullable: false)]
    private Role $role;

    private function __construct(Team $team, Role $role)
    {
        $this->team = $team;
        $this->role = $role;
    }

    public static function onWorkspace(Team $team, Workspace $workspace, Role $role): self
    {
        $access = new self($team, $role);
        $access->workspace = $workspace;

        return $access;
    }

    public static function onProject(Team $team, Project $project, Role $role): self
    {
        $access = new self($team, $role);
        $access->project = $project;

        return $access;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getRole(): string
    {
        return $this->role->getId();
    }

    public function changeRole(Role $role): void
    {
        $this->role = $role;
    }

    public function appliesTo(Project $project): bool
    {
        return (null !== $this->project && $this->project->getId() === $project->getId())
            || (null !== $this->workspace && $this->workspace->getId() === $project->getWorkspace()->getId());
    }

    public function isOn(Workspace $workspace): bool
    {
        return null !== $this->workspace && $this->workspace->getId() === $workspace->getId();
    }
}
