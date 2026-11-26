<?php

declare(strict_types=1);

namespace App\Form\Widget;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the form declaring a site of the widget collects: its address. The
 * controller turns it into AddWidgetSite, which keeps its origin.
 */
final class WidgetSiteData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    public ?string $address = null;
}
