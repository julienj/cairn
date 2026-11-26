<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Project;
use App\Entity\Team;
use App\Message\Query\Query;

/**
 * The teams holding a role on a project, directly or through its
 * workspace, by name: those an issue of the project may be assigned to.
 *
 * @implements Query<list<Team>>
 */
final readonly class ListProjectTeams implements Query
{
    public function __construct(
        public Project $project,
    ) {
    }
}
