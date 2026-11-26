<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Issue;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Whoever an issue is given to, wherever it is drawn small: the team, the
 * person, or the "unassigned" avatar. The one place that knows an issue
 * is given to one or the other; other attributes go to the avatar.
 */
#[AsTwigComponent('Issue:Assignee')]
final class Assignee
{
    public Issue $issue;

    public string $size = 'md';
}
