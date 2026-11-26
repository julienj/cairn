<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\Refs;
use App\Api\Mapper\TrackerMapper;
use App\Api\Mcp\GetProjectTool;
use App\Api\Mcp\ProjectView;
use App\Api\Resource\MemberResource;
use App\Api\Resource\ReleaseResource;
use App\Api\Resource\TagResource;
use App\Api\Resource\TeamResource;
use App\Api\State\Lookup;
use App\Api\State\ProjectProvider;
use App\Entity\User;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListProjectTeams;
use App\Message\Query\Project\ListTrackers;
use App\Message\Query\Release\ListReleases;
use App\Message\Query\Tag\ListTags;
use App\Message\QueryBus;
use App\Security\Viewer;

/**
 * The project is found once, then asked for what the REST API gives
 * under it, through the same queries.
 *
 * @implements ProcessorInterface<GetProjectTool, ProjectView>
 */
final class GetProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly ProjectProvider $projects,
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly TrackerMapper $trackers,
        private readonly Refs $refs,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectView
    {
        \assert($data instanceof GetProjectTool);
        $project = $this->lookup->project($data->key);
        $viewer = $this->viewer->get();

        return new ProjectView(
            $this->projects->one($data->key),
            array_map($this->trackers->map(...), $this->queries->ask(new ListTrackers($viewer, $project, archived: true))),
            array_map(fn (User $user): MemberResource => MemberResource::of($this->refs->user($user)), $this->queries->ask(new ListProjectMembers($project))),
            array_map(ReleaseResource::of(...), $this->queries->ask(new ListReleases($viewer, $project))),
            array_map(TagResource::of(...), $this->queries->ask(new ListTags($project))),
            array_map(TeamResource::of(...), $this->queries->ask(new ListProjectTeams($project))),
        );
    }
}
