<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mcp\UpdateIssueTool;
use App\Api\Resource\IssueResource;
use App\Api\Resource\UpdateIssueInput;
use App\Api\State\Lookup;
use App\Api\State\UpdateIssueProcessor as RestProcessor;
use App\Entity\Enum\Criticality;

/**
 * @implements ProcessorInterface<UpdateIssueTool, IssueResource>
 */
final class UpdateIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RestProcessor $rest,
        private readonly Lookup $lookup,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssueResource
    {
        \assert($data instanceof UpdateIssueTool);

        // Before the command, so that an issue the viewer may not see does not exist.
        $key = $this->lookup->issue($data->key)->getKey();

        $input = new UpdateIssueInput();
        $input->title = $data->title;
        $input->description = $data->description;
        $input->criticality = null === $data->criticality ? null : Criticality::from($data->criticality);
        $input->private = $data->private;
        $input->fields = $data->fields;
        // What the REST processor reads as named by the caller: a null changes nothing here.
        $named = [];
        foreach (['assignee' => $data->assignee, 'assigneeTeam' => $data->assigneeTeam, 'release' => $data->release] as $property => $value) {
            if (null !== $value) {
                $input->{$property} = UpdateIssueTool::NONE === $value ? null : (int) $value;
                $named[$property] = true;
            }
        }

        return $this->rest->update($key, $input, $named);
    }
}
