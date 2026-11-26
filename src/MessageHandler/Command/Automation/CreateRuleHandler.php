<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Automation;

use App\Automation\AutomationException;
use App\Automation\RuleContexts;
use App\Entity\AutomationRule;
use App\Message\Command\Automation\CreateRule;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Repository\TrackerRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateRuleHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TrackerRepository $trackers,
        private readonly RuleContexts $contexts,
        private readonly RuleWriting $writing,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateRule $command): AutomationRule
    {
        $project = $this->projects->find($command->projectId) ?? throw AutomationException::notFound('Project', $command->projectId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::AutomationManage, $project);

        $tracker = null === $command->trackerId ? null : $this->trackers->find($command->trackerId);
        if (null !== $command->trackerId && $tracker?->getProject() !== $project) {
            throw AutomationException::unknownTracker();
        }
        $definition = $this->writing->written($command->definition, $tracker, $this->contexts->ofProject($project));

        $rule = new AutomationRule($project, $command->name, $tracker, $definition);
        if (!$command->active) {
            $rule->deactivate();
        }
        $this->entityManager->persist($rule);

        return $rule;
    }
}
