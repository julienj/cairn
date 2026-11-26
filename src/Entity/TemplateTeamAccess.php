<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TemplateTeamAccessRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A role a project template gives a team on the projects made from it: a
 * rule of the template may aim at a team, which must hold a role on the
 * project to be given its issues. Copied into a TeamAccess when a project
 * is made (TemplateCopy); it grants nothing while it is in the template.
 */
#[ORM\Entity(repositoryClass: TemplateTeamAccessRepository::class)]
#[ORM\Table(name: 'template_team_access')]
#[ORM\UniqueConstraint(name: 'uniq_template_team_access', columns: ['template_id', 'team_id'])]
class TemplateTeamAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ProjectTemplate $template;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Team $team;

    /** The role, an element of the configuration: only its key is read from here. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'role', nullable: false)]
    private Role $role;

    public function __construct(ProjectTemplate $template, Team $team, Role $role)
    {
        $this->template = $template;
        $this->team = $team;
        $this->role = $role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ProjectTemplate
    {
        return $this->template;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    /**
     * The key of the role.
     */
    public function getRole(): string
    {
        return $this->role->getId();
    }

    public function changeRole(Role $role): void
    {
        $this->role = $role;
    }
}
