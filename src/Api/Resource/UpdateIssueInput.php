<?php

declare(strict_types=1);

namespace App\Api\Resource;

use App\Entity\Enum\Criticality;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What PATCH /issues/{key} takes, as a JSON merge patch: a property left
 * out keeps its value, `assignee`, `assigneeTeam` and `release` sent as null empty them,
 * and a field of `fields` sent as null is emptied. UpdateIssueProcessor
 * reads which properties the request names.
 */
final class UpdateIssueInput
{
    #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
    #[Assert\Length(max: 200)]
    public ?string $title = null;

    /** Markdown. */
    public ?string $description = null;

    public ?Criticality $criticality = null;

    /** Id of a member of the project, null to unassign. */
    #[Assert\Positive]
    public ?int $assignee = null;

    /** Id of a team with a role on the project: the issue is given to it instead of a person. Null to unassign. */
    #[Assert\Positive]
    public ?int $assigneeTeam = null;

    public ?bool $private = null;

    /** Id of an open release of the project, null to take the issue out of its release. */
    #[Assert\Positive]
    public ?int $release = null;

    /**
     * Id of a field => new value, null to empty it; the fields left out keep their value.
     *
     * @var array<string, mixed>|null
     */
    public ?array $fields = null;
}
