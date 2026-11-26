<?php

declare(strict_types=1);

namespace App\Api\Resource;

use App\Entity\Enum\Criticality;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What POST /issues takes: CreateIssue without its actor, the project
 * named by its key.
 */
final class CreateIssueInput
{
    /** Key of the project, `MD-SPT`. */
    #[Assert\NotBlank]
    public ?string $project = null;

    /** Id of a category of the project (GET /projects/{key}/trackers). */
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $tracker = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 200)]
    public ?string $title = null;

    /** Markdown. */
    public string $description = '';

    public Criticality $criticality = Criticality::DEFAULT;

    /** Id of a member of the project. */
    #[Assert\Positive]
    public ?int $assignee = null;

    /** Id of a team with a role on the project, instead of `assignee`. */
    #[Assert\Positive]
    public ?int $assigneeTeam = null;

    public bool $private = false;

    /**
     * Id of a field of the category's workflow => value.
     *
     * @var array<string, mixed>
     */
    public array $fields = [];

    /**
     * Uuids of files uploaded beforehand.
     *
     * @var list<string>
     */
    #[Assert\All([new Assert\Uuid()])]
    public array $attachments = [];
}
