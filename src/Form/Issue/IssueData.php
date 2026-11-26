<?php

declare(strict_types=1);

namespace App\Form\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the new issue form collects; the controller turns it into a
 * CreateIssue command. The constraints give the form its errors, the
 * command keeps its own as the guard of the bus.
 */
final class IssueData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 200)]
    public ?string $title = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    public ?string $description = null;

    public Criticality $criticality = Criticality::DEFAULT;

    /** The person or the team the issue is given to. */
    public User|Team|null $assignee = null;

    public bool $private = false;

    /** @var list<Tag> the tags of the project the issue is created with */
    public array $tags = [];

    /** @var array<string, mixed> field id => value, for the fields of the tracker's workflow */
    public array $fields = [];
}
