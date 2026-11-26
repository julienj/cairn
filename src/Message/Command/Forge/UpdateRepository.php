<?php

declare(strict_types=1);

namespace App\Message\Command\Forge;

/**
 * Changes a linked repository, PATCH style: `installed` creates its
 * webhook on the forge through the API with the instance token (true) or
 * removes it (false); `regenerateSecret` gives it a new secret, told to
 * the forge first when the webhook is installed through the API, to
 * paste by hand otherwise. The actor, when there is one, needs
 * `repository.manage`. Handled by UpdateRepositoryHandler.
 */
final readonly class UpdateRepository
{
    public function __construct(
        public int $codeRepositoryId,
        public ?bool $installed = null,
        public bool $regenerateSecret = false,
        public ?int $actorId = null,
    ) {
    }
}
