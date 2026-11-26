<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Config\ConfigRegistry;
use App\Entity\Role;
use App\Entity\TemplateTeamAccess;
use App\Message\Command\Template\GrantTemplateTeamAccess;
use App\Repository\ProjectTemplateRepository;
use App\Repository\TeamRepository;
use App\Repository\TemplateTeamAccessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * As for the access of a team (GrantTeamAccess): the role must exist in
 * the configuration, and an internal-only role goes to an internal team
 * only.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class GrantTemplateTeamAccessHandler
{
    public function __construct(
        private readonly ProjectTemplateRepository $templates,
        private readonly TeamRepository $teams,
        private readonly TemplateTeamAccessRepository $accesses,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(GrantTemplateTeamAccess $command): void
    {
        $definition = $this->config->definition();
        $template = $this->templates->find($command->templateId) ?? throw TemplateException::notFound('Template', $command->templateId);
        $team = $this->teams->find($command->teamId) ?? throw TemplateException::notFound('Team', $command->teamId);
        if (!$definition->hasRole($command->role)) {
            throw TemplateException::unknownRole($command->role);
        }
        $role = $definition->role($command->role);
        if ($role->internalOnly && !$team->isInternal()) {
            throw TemplateException::internalOnlyRole($role->label, $team->getName());
        }
        $granted = $this->config->row(Role::class, $role->id);

        $access = $this->accesses->findOneBy(['template' => $template, 'team' => $team]) ?? new TemplateTeamAccess($template, $team, $granted);
        $access->changeRole($granted);
        $this->entityManager->persist($access);
    }
}
