<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Widget;

use App\Message\Command\Widget\UnlinkWidget;
use App\Repository\WidgetTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A token of someone else is reported as not found: nobody learns which
 * ids exist.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UnlinkWidgetHandler
{
    public function __construct(
        private readonly WidgetTokenRepository $tokens,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UnlinkWidget $command): void
    {
        $token = $this->tokens->find($command->tokenId);
        if (null === $token || $token->getUser()->getId() !== $command->actorId) {
            throw WidgetException::notFound('Widget token', $command->tokenId);
        }

        $this->entityManager->remove($token);
    }
}
