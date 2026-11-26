<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\IssueMapper;
use App\Api\Resource\CreateIssueInput;
use App\Api\Resource\IssueResource;
use App\Entity\Issue;
use App\Message\Command\Issue\CreateIssue;
use App\Security\Viewer;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<CreateIssueInput, IssueResource>
 */
final class CreateIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly IssueMapper $issues,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssueResource
    {
        \assert($data instanceof CreateIssueInput);
        $project = $this->lookup->findProject((string) $data->project) ?? throw new UnprocessableEntityHttpException('project: no such project.');

        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->viewer->get()->getId(),
            projectId: (int) $project->getId(),
            trackerId: (int) $data->tracker,
            title: (string) $data->title,
            description: $data->description,
            criticality: $data->criticality,
            assigneeId: $data->assignee,
            private: $data->private,
            fields: $data->fields,
            attachments: $data->attachments,
            assigneeTeamId: $data->assigneeTeam,
        ));
        \assert($issue instanceof Issue);

        return $this->issues->detail($this->lookup->detail($issue->getKey()));
    }
}
