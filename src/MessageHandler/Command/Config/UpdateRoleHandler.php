<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Role;
use App\Message\Command\Config\UpdateRole;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\MembershipRepository;
use App\Repository\RoleRepository;
use App\Repository\TeamAccessRepository;
use App\Repository\TemplateTeamAccessRepository;
use App\Repository\TransitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateRoleHandler
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly MembershipRepository $memberships,
        private readonly TeamAccessRepository $teamAccesses,
        private readonly TemplateTeamAccessRepository $templateAccesses,
        private readonly TransitionRepository $transitions,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(UpdateRole $command): void
    {
        $role = $this->writing->get(Role::class, $command->id);

        if (true === $command->internalOnly && !$role->isInternalOnly()) {
            // An internal-only role never goes to an external user nor to an external team: those who hold it come first.
            $external = $this->memberships->countHolding($role->getId())['external'] + $this->teamAccesses->countHolding($role->getId())['external'] + $this->templateAccesses->countHolding($role->getId())['external'];
            if ($external > 0) {
                throw ConfigChangeException::roleHeldByExternalUsers($role->getLabel(), $external);
            }
        }
        if (false === $command->internalOnly && $role->isInternalOnly()) {
            foreach ($this->roles->grantsOf($role) as $grant) {
                if ($grant->getPermission()->internalOnly()) {
                    throw ConfigChangeException::roleHoldsInternalPermissions($role->getLabel());
                }
            }
        }

        if (null !== $command->label) {
            $role->rename($command->label);
        }
        if (null !== $command->internalOnly) {
            $command->internalOnly ? $role->makeInternalOnly() : $role->openToExternalUsers();
        }
        if (null !== $command->followsNewIssues) {
            $command->followsNewIssues ? $role->followNewIssues() : $role->stopFollowingNewIssues();
        }
        // An action is for the roles held to the workflow: freed, the role leaves those that named it, for good.
        $left = 0;
        if (null !== $command->freeWorkflow) {
            if ($command->freeWorkflow && !$role->hasFreeWorkflow()) {
                $namings = $this->transitions->namingsOf($role);
                $left = \count($namings);
                array_map($this->entityManager->remove(...), $namings);
            }
            $command->freeWorkflow ? $role->freeFromWorkflow() : $role->holdToWorkflow();
        }
        if (null !== $command->position) {
            ListOrder::move($role, $this->roles->findOrdered(), $command->position);
        }
        $this->writing->settle($left > 0 ? 'role.freed' : 'role.updated', ['%name%' => $role->getLabel(), '%count%' => $left]);
    }
}
