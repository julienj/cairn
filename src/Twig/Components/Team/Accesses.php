<?php

declare(strict_types=1);

namespace App\Twig\Components\Team;

use App\Config\ConfigRegistry;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\TeamAccess;
use App\Entity\TeamMember;
use App\Entity\Workspace;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\Command\Team\RevokeTeamAccess;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjects;
use App\Message\Query\Workspace\ListWorkspaces;
use App\Message\QueryBus;
use App\Repository\TeamAccessRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Twig\Components\ManagesRoleRows;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The roles granted to teams: those of a team (where it holds one), of a
 * workspace or of a project (which teams hold one there), exactly one of
 * the three. A role is granted, changed, taken back after a confirmation
 * in place. The rules are GrantTeamAccess's; a refusal is shown in place.
 * The first two serve the administration, which acts without an actor. On
 * a project the viewer acts, and the roles the teams hold on the
 * workspace are shown read-only, a role granted here only widening them.
 * The component lists the teams of the instance, so it checks who draws
 * it each time (`guard()`), not only the page that holds it.
 */
#[AsLiveComponent('Team:Accesses')]
final class Accesses
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use ManagesRoleRows;

    #[LiveProp]
    public ?Team $team = null;

    #[LiveProp]
    public ?Workspace $workspace = null;

    #[LiveProp]
    public ?Project $project = null;

    /** On a workspace or a project: the team that gets the new role. */
    #[LiveProp(writable: true)]
    public ?int $newTeamId = null;

    /** For a team: where the new role applies, `workspace-3` or `project-5`. */
    #[LiveProp(writable: true)]
    public string $newTarget = '';

    #[LiveProp(writable: true)]
    public string $newRole = '';

    /** @var list<TeamAccess>|null read once per render, `align()` forgets it */
    private ?array $loadedAccesses = null;

    /** @var list<array{workspace: Workspace, projects: list<Project>}>|null read once per render */
    private ?array $loadedTargets = null;

    /** @var array<int, TeamAccess>|null read once per render, `align()` forgets it */
    private ?array $loadedInherited = null;

    /** @var array<int, string> team id => the names of its members, read once per team and per render */
    private array $loadedMembers = [];

    public function __construct(
        private readonly TeamAccessRepository $accesses,
        private readonly TeamRepository $teams,
        private readonly TeamMemberRepository $members,
        private readonly QueryBus $queries,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
        private readonly Viewer $viewer,
        private readonly Security $security,
    ) {
    }

    public function mount(?Team $team = null, ?Workspace $workspace = null, ?Project $project = null): void
    {
        if (1 !== \count(array_filter([$team, $workspace, $project]))) {
            throw new \LogicException('Team:Accesses lists the accesses of a team, of a workspace or of a project.');
        }
        $this->team = $team;
        $this->workspace = $workspace;
        $this->project = $project;
        $this->align();
    }

    /**
     * Who draws the component, and calls its actions, each time: on a
     * project whoever manages its members, the administration otherwise.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $granted = null !== $this->project
            ? $this->security->isGranted(Permission::ProjectManageMembers->value, $this->project)
            : $this->security->isGranted('ROLE_ADMIN');
        if (!$granted) {
            throw new AccessDeniedException('Managing these accesses needs project.manage_members on the project, or the administration.');
        }
    }

    /**
     * @return list<TeamAccess>
     */
    public function getAccesses(): array
    {
        return $this->loadedAccesses ??= match (true) {
            null !== $this->team => $this->accesses->findOfTeam($this->team),
            null !== $this->workspace => $this->accesses->findOnWorkspace($this->workspace),
            null !== $this->project => $this->accesses->findOnProject($this->project),
            default => throw new \LogicException('Team:Accesses needs a team, a workspace or a project.'),
        };
    }

    /**
     * On a project: the roles its workspace gives to teams, by team id.
     *
     * @return array<int, TeamAccess>
     */
    public function getInherited(): array
    {
        if (null === $this->loadedInherited) {
            $this->loadedInherited = [];
            foreach (null !== $this->project ? $this->accesses->findOnWorkspace($this->project->getWorkspace()) : [] as $access) {
                $this->loadedInherited[(int) $access->getTeam()->getId()] = $access;
            }
        }

        return $this->loadedInherited;
    }

    /**
     * Who is part of a team, by name, as a row says it.
     */
    public function membersOf(Team $team): string
    {
        return $this->loadedMembers[(int) $team->getId()] ??= implode(', ', array_map(static fn (TeamMember $member): string => $member->getUser()->getFullName(), $this->members->findOfTeam($team)));
    }

    /**
     * On a workspace or a project: the teams that hold no role there yet.
     *
     * @return list<Team>
     */
    public function getCandidates(): array
    {
        $holding = array_map(static fn (TeamAccess $access): ?int => $access->getTeam()->getId(), $this->getAccesses());

        return array_values(array_filter(
            $this->teams->findAllByName(),
            fn (Team $team): bool => !\in_array($team->getId(), $holding, true) && [] !== $this->rolesFor($team),
        ));
    }

    public function getNewTeam(): ?Team
    {
        return null !== $this->newTeamId ? $this->teams->find($this->newTeamId) : null;
    }

    /**
     * For a team: the workspaces, each with its projects.
     *
     * @return list<array{workspace: Workspace, projects: list<Project>}>
     */
    public function getTargets(): array
    {
        if (null === $this->loadedTargets) {
            $projects = [];
            foreach ($this->queries->ask(new ListProjects()) as $project) {
                $projects[(int) $project->getWorkspace()->getId()][] = $project;
            }
            $this->loadedTargets = [];
            foreach ($this->queries->ask(new ListWorkspaces()) as $workspace) {
                $this->loadedTargets[] = ['workspace' => $workspace, 'projects' => $projects[(int) $workspace->getId()] ?? []];
            }
        }

        return $this->loadedTargets;
    }

    /**
     * What the chosen target reads as, for the picker's button.
     */
    public function getTargetLabel(): ?string
    {
        [$kind, $id] = explode('-', $this->newTarget.'-', 3) + [1 => ''];
        foreach ($this->getTargets() as ['workspace' => $workspace, 'projects' => $projects]) {
            if ('workspace' === $kind && $workspace->getId() === (int) $id) {
                return $this->translator->trans('admin.access.whole_workspace', ['%name%' => $workspace->getName()]);
            }
            foreach ($projects as $project) {
                if ('project' === $kind && $project->getId() === (int) $id) {
                    return $project->getIssuePrefix().' · '.$project->getName();
                }
            }
        }

        return null;
    }

    /**
     * The roles a team may be given here: none of the internal-only ones
     * for an external team and, on a project, those that widen the role
     * the team holds on the workspace.
     *
     * @return array<string, string> role id => label
     */
    public function rolesFor(?Team $team): array
    {
        $definition = $this->config->definition();
        $inherited = null !== $team ? ($this->getInherited()[(int) $team->getId()] ?? null)?->getRole() : null;
        $ceiling = null !== $inherited ? $definition->roleRank($inherited) : null;

        $roles = [];
        foreach ($definition->roles as $role) {
            if ($role->internalOnly && false === $team?->isInternal()) {
                continue;
            }
            if (null !== $ceiling && $definition->roleRank($role->id) >= $ceiling) {
                continue;
            }
            $roles[$role->id] = $role->label;
        }

        return $roles;
    }

    #[LiveAction]
    public function chooseTeam(#[LiveArg] int $team): void
    {
        $this->newTeamId = $team;
    }

    #[LiveAction]
    public function chooseTarget(#[LiveArg] string $target): void
    {
        $this->newTarget = $target;
    }

    #[LiveAction]
    public function grant(CommandBus $commands): void
    {
        $this->error = null;
        if (null !== $this->team) {
            [$kind, $id] = explode('-', $this->newTarget.'-', 3) + [1 => ''];
            $command = match ($kind) {
                'workspace' => new GrantTeamAccess((int) $this->team->getId(), (int) $id, null, $this->newRole),
                'project' => new GrantTeamAccess((int) $this->team->getId(), null, (int) $id, $this->newRole),
                default => null,
            };
        } else {
            $command = null !== $this->newTeamId ? new GrantTeamAccess($this->newTeamId, $this->workspace?->getId(), $this->project?->getId(), $this->newRole, $this->actorId()) : null;
        }
        if (null === $command || '' === $this->newRole) {
            $this->error = $this->translator->trans('admin.team.access.incomplete');

            return;
        }
        if (!$this->tryCommand($commands, $command)) {
            return;
        }

        $this->newTeamId = null;
        $this->newTarget = '';
        $this->newRole = '';
        $this->done('admin.access.granted');
    }

    #[LiveAction]
    public function changeRole(CommandBus $commands, #[LiveArg] int $access): void
    {
        $this->error = null;
        $current = $this->accesses->find($access);
        if (null === $current) {
            return;
        }
        $command = new GrantTeamAccess(
            (int) $current->getTeam()->getId(),
            $current->getWorkspace()?->getId(),
            $current->getProject()?->getId(),
            $this->roles[$access] ?? '',
            $this->actorId(),
        );
        if (!$this->tryCommand($commands, $command)) {
            $this->align();

            return;
        }

        $this->done('admin.access.changed');
    }

    #[LiveAction]
    public function revoke(CommandBus $commands): void
    {
        $this->error = null;
        if (null !== $this->revoking && $this->tryCommand($commands, new RevokeTeamAccess($this->revoking, $this->actorId()))) {
            $this->done('admin.access.revoked');
        }
        $this->revoking = null;
    }

    /**
     * On a project, the viewer acts and their rights are checked; the
     * administration acts without an actor.
     */
    private function actorId(): ?int
    {
        return null !== $this->project ? $this->viewer->get()->getId() : null;
    }

    /**
     * Aligns the role of each row with the database, read again.
     */
    private function align(): void
    {
        $this->loadedAccesses = null;
        $this->loadedInherited = null;
        $this->roles = [];
        foreach ($this->getAccesses() as $access) {
            $this->roles[(int) $access->getId()] = $access->getRole();
        }
    }
}
