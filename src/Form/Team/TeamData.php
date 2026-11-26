<?php

declare(strict_types=1);

namespace App\Form\Team;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the team forms collect: its name, and at creation only whether it
 * is internal. The controller turns it into CreateTeam or UpdateTeam.
 */
final class TeamData
{
    public const string INTERNAL = 'internal';
    public const string EXTERNAL = 'external';

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\Choice(choices: [self::INTERNAL, self::EXTERNAL])]
    public string $nature = self::INTERNAL;
}
