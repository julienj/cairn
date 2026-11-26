<?php

declare(strict_types=1);

namespace App\Message\Command\Forge;

use App\Entity\CodeRepository;
use App\Entity\Enum\Forge;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Links a repository of a forge to a project, with a secret of its own,
 * without touching the forge: UpdateRepository does, or the manager
 * installs the webhook by hand. The actor, when there is one, needs
 * `repository.manage`. Handled by LinkRepositoryHandler, which returns the
 * CodeRepository.
 */
final readonly class LinkRepository
{
    public function __construct(
        public int $projectId,
        public Forge $forge,
        /** `group/project`, as in the repository's URL */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        #[Assert\Regex(CodeRepository::PATH_PATTERN, message: 'code_repository.path_invalid')]
        public string $path,
        public ?int $actorId = null,
    ) {
    }
}
