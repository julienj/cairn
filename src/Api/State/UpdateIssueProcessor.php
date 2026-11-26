<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\IssueMapper;
use App\Api\Resource\IssueResource;
use App\Api\Resource\UpdateIssueInput;
use App\Message\Command\Issue\UpdateIssue;
use App\Security\Viewer;
use Symfony\Component\HttpFoundation\Request;

/**
 * PATCH /issues/{key}. UpdateIssue reads null as "keep", the merge patch
 * reads it as "empty": the properties the request names tell them apart,
 * so `"assignee": null` unassigns, as `"assigneeTeam": null` does, and a property left out changes
 * nothing, nor asks for its right.
 *
 * @implements ProcessorInterface<UpdateIssueInput, IssueResource>
 */
final class UpdateIssueProcessor implements ProcessorInterface
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
        \assert($data instanceof UpdateIssueInput);
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);

        return $this->update((string) $uriVariables['key'], $data, $request->toArray());
    }

    /**
     * @param array<array-key, mixed> $sent what the caller named: the body of the PATCH, the arguments of the MCP tool
     */
    public function update(string $key, UpdateIssueInput $data, array $sent): IssueResource
    {
        // A person or a team: naming one as null unassigns, unless the other is given the issue in the same request.
        $assignee = \array_key_exists('assignee', $sent) ? $data->assignee ?? UpdateIssue::NOBODY : null;
        $unassigned = \array_key_exists('assigneeTeam', $sent) && null === $data->assigneeTeam;
        if (null !== $data->assigneeTeam && UpdateIssue::NOBODY === $assignee) {
            $assignee = null;
        }

        $this->commands->dispatch(new UpdateIssue(
            actorId: (int) $this->viewer->get()->getId(),
            issueKey: $key,
            title: $data->title,
            description: $data->description,
            fields: $data->fields,
            assigneeId: $assignee ?? ($unassigned ? UpdateIssue::NOBODY : null),
            criticality: $data->criticality,
            private: $data->private,
            releaseId: \array_key_exists('release', $sent) ? $data->release ?? UpdateIssue::NOBODY : null,
            assigneeTeamId: $data->assigneeTeam,
        ));

        return $this->issues->detail($this->lookup->detail($key));
    }
}
