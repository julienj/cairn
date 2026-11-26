<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Automation;

use App\Automation\AutomationException;
use App\Message\Command\Automation\DeleteRule;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\AutomationRuleRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes the rule, one of the few physical deletions: it is the current
 * configuration of the project. Its executions stay, without it.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteRuleHandler
{
    public function __construct(
        private readonly AutomationRuleRepository $rules,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteRule $command): void
    {
        $rule = $this->rules->find($command->ruleId) ?? throw AutomationException::notFound('Rule', $command->ruleId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::AutomationManage, $rule->getProject());
        $this->entityManager->remove($rule);
    }
}
