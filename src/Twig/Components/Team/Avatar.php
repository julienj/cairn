<?php

declare(strict_types=1);

namespace App\Twig\Components\Team;

use App\Entity\Team;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A team wherever it is drawn small: its initials in a square, on the
 * colour of its kind (internal or external), where a person is a disc.
 * The one place that turns a Team into what `Ui:Avatar` draws; other
 * attributes go to it.
 */
#[AsTwigComponent('Team:Avatar')]
final class Avatar
{
    public Team $team;

    public string $size = 'md';
}
