<?php

declare(strict_types=1);

namespace App\Message\Command\Tag;

use App\Entity\Enum\TagColour;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a tag, PATCH style: a property left null keeps its value. The
 * issues that carry it follow, their journal keeps the name of the time.
 * The actor needs `tag.manage`. Handled by UpdateTagHandler.
 */
final readonly class UpdateTag
{
    public function __construct(
        public int $actorId,
        public int $tagId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 40)]
        public ?string $name = null,
        public ?TagColour $colour = null,
    ) {
    }
}
