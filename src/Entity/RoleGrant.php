<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A role given on a workspace or on a project, to a person (Membership)
 * or to a team (TeamAccess): what AccessControl reads to tell the role
 * someone has somewhere, whoever it was given to.
 */
interface RoleGrant
{
    /**
     * The key of the role.
     */
    public function getRole(): string;

    /**
     * Whether it gives a role on the project, directly or through its
     * workspace.
     */
    public function appliesTo(Project $project): bool;

    public function isOn(Workspace $workspace): bool;
}
