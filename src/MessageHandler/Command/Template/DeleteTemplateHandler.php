<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Message\Command\Template\DeleteTemplate;
use App\Repository\ProjectTemplateRepository;
use App\Repository\TemplateRuleRepository;
use App\Repository\TemplateTeamAccessRepository;
use App\Repository\TemplateTrackerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes the template for good, its rules, the roles it gives to teams
 * then its trackers with it: it
 * is configuration, and no project points to it.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTemplateHandler
{
    public function __construct(
        private readonly ProjectTemplateRepository $templates,
        private readonly TemplateTrackerRepository $trackers,
        private readonly TemplateRuleRepository $rules,
        private readonly TemplateTeamAccessRepository $teamAccesses,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteTemplate $command): void
    {
        $template = $this->templates->find($command->templateId) ?? throw TemplateException::notFound('Template', $command->templateId);

        foreach ($this->rules->findByTemplate($template) as $rule) {
            $this->entityManager->remove($rule);
        }
        foreach ($this->teamAccesses->findByTemplate($template) as $access) {
            $this->entityManager->remove($access);
        }
        foreach ($this->trackers->findByTemplate($template) as $tracker) {
            $this->entityManager->remove($tracker);
        }
        $this->entityManager->remove($template);
    }
}
