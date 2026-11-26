<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Message\Command\Template\RevokeTemplateTeamAccess;
use App\Repository\TemplateTeamAccessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RevokeTemplateTeamAccessHandler
{
    public function __construct(
        private readonly TemplateTeamAccessRepository $accesses,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RevokeTemplateTeamAccess $command): void
    {
        $access = $this->accesses->find($command->accessId) ?? throw TemplateException::notFound('Access', $command->accessId);
        $this->entityManager->remove($access);
    }
}
