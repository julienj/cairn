<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Project;

use App\Entity\Team;
use App\Message\Query\Project\ListProjectTeams;
use App\Repository\TeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListProjectTeamsHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
    ) {
    }

    /**
     * @return list<Team>
     */
    public function __invoke(ListProjectTeams $query): array
    {
        return $this->teams->findOnProject($query->project);
    }
}
