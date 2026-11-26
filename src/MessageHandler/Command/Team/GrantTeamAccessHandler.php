<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Config\ConfigRegistry;
use App\Entity\Role;
use App\Entity\TeamAccess;
use App\Message\Command\Team\GrantTeamAccess;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Repository\TeamAccessRepository;
use App\Repository\TeamRepository;
use App\Repository\WorkspaceRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The role must exist in the configuration, an internal-only role goes to
 * an internal team only, and a role granted on a project must widen the
 * one the team holds on its workspace: a narrower one would change
 * nothing, the broadest role wins (AccessControl::roleOn). The team is
 * compared with itself only, never with what its members hold. An actor,
 * from a project, grants on that project only (ProjectActor).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class GrantTeamAccessHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TeamAccessRepository $accesses,
        private readonly WorkspaceRepository $workspaces,
        private readonly ProjectRepository $projects,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(GrantTeamAccess $command): void
    {
        $definition = $this->config->definition();
        $team = $this->teams->find($command->teamId) ?? throw TeamException::notFound('Team', $command->teamId);
        if (!$definition->hasRole($command->role)) {
            throw TeamException::unknownRole($command->role);
        }
        $role = $definition->role($command->role);
        // The row of the role, which the access points to; what the role says is read from the definition.
        $granted = $this->config->row(Role::class, $role->id);
        if ($role->internalOnly && !$team->isInternal()) {
            throw TeamException::internalOnlyRole($role->label, $team->getName());
        }

        if (null !== $command->projectId) {
            $project = $this->projects->find($command->projectId) ?? throw TeamException::notFound('Project', $command->projectId);
            $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, $project);
            $inherited = $this->accesses->findOne($team, $project->getWorkspace());
            if (null !== $inherited && $definition->roleRank($role->id) >= $definition->roleRank($inherited->getRole())) {
                throw TeamException::notWiderThanInherited($role->label, $definition->role($inherited->getRole())->label);
            }
            $access = $this->accesses->findOne($team, $project) ?? TeamAccess::onProject($team, $project, $granted);
        } else {
            $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, null);
            $workspaceId = (int) $command->workspaceId;
            $workspace = $this->workspaces->find($workspaceId) ?? throw TeamException::notFound('Workspace', $workspaceId);
            $access = $this->accesses->findOne($team, $workspace) ?? TeamAccess::onWorkspace($team, $workspace, $granted);
        }

        $access->changeRole($granted);
        $this->entityManager->persist($access);
    }
}
