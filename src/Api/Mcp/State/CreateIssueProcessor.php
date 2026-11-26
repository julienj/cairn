<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mcp\CreateIssueTool;
use App\Api\Resource\CreateIssueInput;
use App\Api\Resource\IssueResource;
use App\Api\State\CreateIssueProcessor as RestProcessor;
use App\Entity\Enum\Criticality;

/**
 * @implements ProcessorInterface<CreateIssueTool, IssueResource>
 */
final class CreateIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RestProcessor $rest,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssueResource
    {
        \assert($data instanceof CreateIssueTool);

        $input = new CreateIssueInput();
        $input->project = $data->project;
        $input->tracker = $data->tracker;
        $input->title = $data->title;
        $input->description = $data->description;
        $input->criticality = Criticality::from($data->criticality);
        $input->assignee = $data->assignee;
        $input->assigneeTeam = $data->assigneeTeam;
        $input->private = $data->private;
        $input->fields = $data->fields;

        return $this->rest->process($input, $operation);
    }
}
