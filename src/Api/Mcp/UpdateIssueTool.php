<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\UpdateIssueProcessor;
use App\Api\Resource\IssueResource;
use App\Entity\Enum\Criticality;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What UpdateIssue takes, without its actor. Nothing is emptied by a null:
 * an assistant fills the arguments it does not use with one, so `none` is
 * the word that unassigns, as in the filters of a list.
 */
#[McpTool(
    name: 'update_issue',
    description: 'Changes the properties given, each needing its own right; those left out, or null, keep their value. `assignee` and `release` take `none` to unassign the issue and to take it out of its release. An issue is assigned to a person (`assignee`) or to a team (`assigneeTeam`), one or the other. The step is changed with move_issue.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.write')",
    validate: true,
    output: IssueResource::class,
    processor: UpdateIssueProcessor::class,
)]
final class UpdateIssueTool
{
    public const string NONE = 'none';
    private const string ID_OR_NONE = '/^(none|[1-9]\d{0,8})$/';

    /** Key of the issue, `MD-SPT-12`. */
    #[Assert\NotBlank]
    public string $key = '';

    #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
    #[Assert\Length(max: 200)]
    public ?string $title = null;

    /** Markdown. */
    public ?string $description = null;

    #[Assert\Choice(callback: [Criticality::class, 'values'])]
    public ?string $criticality = null;

    /** Id of a member of the project, or `none` to unassign: from a person as from a team. */
    #[Assert\Regex(self::ID_OR_NONE, message: 'An id, or none.')]
    public ?string $assignee = null;

    /** Id of a team with a role on the project (get_project lists them): the issue is given to it instead of a person. */
    #[Assert\Positive]
    public ?int $assigneeTeam = null;

    public ?bool $private = null;

    /** Id of an open release of the project, or `none` to take the issue out of its release. */
    #[Assert\Regex(self::ID_OR_NONE, message: 'An id, or none.')]
    public ?string $release = null;

    /**
     * Id of a field => new value, null to empty it; the fields left out keep their value.
     *
     * @var array<string, mixed>|null
     */
    public ?array $fields = null;
}
