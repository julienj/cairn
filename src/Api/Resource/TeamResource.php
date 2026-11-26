<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Api\State\TeamCollectionProvider;
use App\Entity\Team;

/**
 * A team with a role on a project: what an issue may be assigned to
 * instead of a person.
 */
#[ApiResource(
    shortName: 'Team',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{key}/teams',
            uriVariables: ['key' => new Link(fromClass: ProjectResource::class)],
            description: 'The teams with a role on the project, by name.',
            paginationEnabled: false,
            provider: TeamCollectionProvider::class,
        ),
    ],
)]
final readonly class TeamResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public string $name,
        public bool $internal,
    ) {
    }

    public static function of(Team $team): self
    {
        return new self((int) $team->getId(), $team->getName(), $team->isInternal());
    }
}
