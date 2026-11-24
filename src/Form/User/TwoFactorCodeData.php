<?php

declare(strict_types=1);

namespace App\Form\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A code typed on the page of the second factor: the one of the app that is being set up, or the proof that the
 * person holds it before they change it. The controller turns it into the command.
 */
final class TwoFactorCodeData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public ?string $code = null;
}
