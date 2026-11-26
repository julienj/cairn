<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MembershipRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A role granted to a user on a workspace or on a project, exactly one of
 * the two. The role is an id of the instance configuration. A workspace
 * membership applies to every project of the workspace; a project
 * membership may only widen it (see AccessControl).
 */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_workspace', columns: ['user_id', 'workspace_id'])]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_project', columns: ['user_id', 'project_id'])]
class Membership implements RoleGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne]
    private ?Project $project = null;

    /** The role, an element of the configuration: only its key is read from here, what it says comes from Config\ConfigRegistry. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'role', nullable: false)]
    private Role $role;

    /**
     * Whether this person is the one the role answers through where the
     * membership applies: the project, or every project of the workspace
     * that names no referent of its own (see Security\Referents). Nobody
     * is one until someone says so.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $referent = false;

    private function __construct(User $user, Role $role)
    {
        $this->user = $user;
        $this->role = $role;
    }

    public static function onWorkspace(User $user, Workspace $workspace, Role $role): self
    {
        $membership = new self($user, $role);
        $membership->workspace = $workspace;

        return $membership;
    }

    public static function onProject(User $user, Project $project, Role $role): self
    {
        $membership = new self($user, $role);
        $membership->project = $project;

        return $membership;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    /**
     * The key of the role.
     */
    public function getRole(): string
    {
        return $this->role->getId();
    }

    /**
     * A referent is the referent of a role: another role takes the mark away.
     */
    public function changeRole(Role $role): void
    {
        if ($role->getId() !== $this->role->getId()) {
            $this->referent = false;
        }
        $this->role = $role;
    }

    public function isReferent(): bool
    {
        return $this->referent;
    }

    public function designateReferent(): void
    {
        $this->referent = true;
    }

    public function withdrawReferent(): void
    {
        $this->referent = false;
    }

    /**
     * Whether the membership gives a role on the project, directly or
     * through its workspace.
     */
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
