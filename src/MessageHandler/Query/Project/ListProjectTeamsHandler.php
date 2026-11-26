<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Project;

use App\Entity\Team;
use App\Entity\TeamAccess;
use App\Message\Query\Project\ListProjectTeams;
use App\Repository\EntityList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The teams of a project as ListProjectMembers gives its people: a team
 * is one of them when an access of its own, on the project or on its
 * workspace, gives it a role. TeamAccessRepository::holdsRoleOn() asks
 * the same of one team.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListProjectTeamsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Team>
     */
    public function __invoke(ListProjectTeams $query): array
    {
        return EntityList::of(
            $this->entityManager->createQueryBuilder()
                ->select('t')
                ->from(Team::class, 't')
                ->where(\sprintf('EXISTS (SELECT 1 FROM %s a WHERE a.team = t AND (a.project = :project OR a.workspace = :workspace))', TeamAccess::class))
                ->setParameter('project', $query->project)
                ->setParameter('workspace', $query->project->getWorkspace())
                ->orderBy('t.name')
                ->getQuery(),
            Team::class,
        );
    }
}
