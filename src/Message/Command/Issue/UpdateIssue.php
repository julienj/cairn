<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use App\Entity\Enum\Criticality;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes the properties of an issue, PATCH style: a property left null
 * keeps its value. Each change is checked against its own right and
 * journaled; one refusal refuses them all. Handled by UpdateIssueHandler.
 */
#[Assert\Expression('this.assigneeId === null or this.assigneeTeamId === null', message: 'An issue is assigned to a person or to a team.')]
final readonly class UpdateIssue
{
    public const int NOBODY = 0;

    /**
     * @param array<string, mixed>|null $fields         field id => new value, null to empty it; the fields not listed keep their value
     * @param int|null                  $assigneeId     the new assignee, NOBODY to unassign: the team the issue was given to leaves it too
     * @param int|null                  $assigneeTeamId the team the issue is given to, instead of a person: one or the other
     * @param int|null                  $releaseId      an open release of the project, NOBODY to take the issue out of its release
     */
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 200)]
        public ?string $title = null,
        public ?string $description = null,
        public ?array $fields = null,
        #[Assert\PositiveOrZero]
        public ?int $assigneeId = null,
        public ?Criticality $criticality = null,
        public ?bool $private = null,
        #[Assert\PositiveOrZero]
        public ?int $releaseId = null,
        #[Assert\Positive]
        public ?int $assigneeTeamId = null,
    ) {
    }
}
