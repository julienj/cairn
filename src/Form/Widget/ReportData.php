<?php

declare(strict_types=1);

namespace App\Form\Widget;

use App\Entity\Enum\Criticality;
use App\Entity\Tag;
use App\Entity\Tracker;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the form of the widget collects; the controller turns it into a
 * CreateIssue command. The constraints give the form its errors, the
 * command keeps its own as the guard of the bus.
 */
final class ReportData
{
    #[Assert\NotNull]
    public ?Tracker $tracker = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 200)]
    public ?string $title = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    public ?string $description = null;

    /** Null when the reporter may not set it, or sent none: the default one then. */
    public ?Criticality $criticality = Criticality::DEFAULT;

    public bool $private = false;

    /** @var list<Tag> the tags of the project the issue is created with */
    public array $tags = [];

    /** @var array<string, mixed> field id => value, for the fields the tracker's workflow requires */
    public array $fields = [];

    /** What the site noted of the visit, as JSON, as its script handed it to the frame. */
    public ?string $technical = null;

    /** Whether the reporter leaves that report with the issue. */
    public bool $includeReport = true;
}
