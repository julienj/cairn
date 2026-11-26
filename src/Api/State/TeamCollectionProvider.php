<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Resource\TeamResource;
use App\Message\Query\Project\ListProjectTeams;
use App\Message\QueryBus;

/**
 * GET /projects/{key}/teams.
 *
 * @implements ProviderInterface<TeamResource>
 */
final class TeamCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly Lookup $lookup,
    ) {
    }

    /**
     * @return list<TeamResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(TeamResource::of(...), $this->queries->ask(new ListProjectTeams($this->lookup->project((string) $uriVariables['key']))));
    }
}
