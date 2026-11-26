<?php

declare(strict_types=1);

namespace App\Message\Command\Tag;

use App\Entity\Enum\TagColour;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates a tag in a project, under a name no other tag of the project
 * has. The actor needs `tag.manage`. Handled by CreateTagHandler,
 * which returns the Tag.
 */
final readonly class CreateTag
{
    public function __construct(
        public int $actorId,
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 40)]
        public string $name,
        public TagColour $colour,
    ) {
    }
}
