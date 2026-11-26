<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\CreateIssueProcessor;
use App\Api\Resource\IssueResource;
use App\Entity\Enum\Criticality;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What CreateIssue takes, without its actor.
 */
#[McpTool(
    name: 'create_issue',
    description: 'Creates an issue in a category of a project. Its author is the user.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.write')",
    validate: true,
    output: IssueResource::class,
    processor: CreateIssueProcessor::class,
)]
final class CreateIssueTool
{
    /** Key of the project, `MD-SPT`. */
    #[Assert\NotBlank]
    public string $project = '';

    /** Id of a category of the project (get_project lists them). */
    #[Assert\Positive]
    public int $tracker = 0;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    /** Markdown. */
    public string $description = '';

    #[Assert\Choice(callback: [Criticality::class, 'values'])]
    public string $criticality = Criticality::DEFAULT->value;

    /** Id of a member of the project. */
    #[Assert\Positive]
    public ?int $assignee = null;

    /** Id of a team with a role on the project (get_project lists them), instead of `assignee`. */
    #[Assert\Positive]
    public ?int $assigneeTeam = null;

    /** True to hide the issue from the client. */
    public bool $private = false;

    /**
     * Id of a field of the category's workflow => value.
     *
     * @var array<string, mixed>
     */
    public array $fields = [];
}
