<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Project;

use App\Automation\AutomationException;
use App\Config\ConfigRegistry;
use App\Entity\AutomationRule;
use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Entity\Role;
use App\Entity\TeamAccess;
use App\Entity\Tracker;
use App\Entity\Workflow;
use App\Repository\TeamAccessRepository;
use App\Repository\TemplateRuleRepository;
use App\Repository\TemplateTeamAccessRepository;
use App\Repository\TemplateTrackerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives a project that is being created what a template holds: its
 * trackers, in their order, then its automation rules, in the order they
 * were written, each limited to the tracker that was made from the one it
 * names, then the roles it gives to teams, so that a rule that aims at a
 * team finds it on the project. The entities are built here, inside the transaction of
 * CreateProject, not through CreateTracker and CreateRule: those read the
 * database, which does not hold the project yet. A rule was checked when
 * it was written in the template and is copied as it is, switched on or
 * off as it was; one that stopped meaning something since (a step taken
 * out of its workflow) blocks nothing, as in a project, and one that
 * cannot be read any more is left out, which the logs say.
 */
final class TemplateCopy
{
    public function __construct(
        private readonly TemplateTrackerRepository $trackers,
        private readonly TemplateRuleRepository $rules,
        private readonly TemplateTeamAccessRepository $teamAccesses,
        private readonly TeamAccessRepository $accesses,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function into(Project $project, ProjectTemplate $template): void
    {
        $made = [];
        foreach ($this->trackers->findByTemplate($template) as $position => $source) {
            $tracker = new Tracker($project, $source->getName(), $this->config->row(Workflow::class, $source->getWorkflowId()), $position);
            $tracker->setDescription($source->getDescription());
            if ($source->isPrivate()) {
                $tracker->makePrivate();
            }
            $this->entityManager->persist($tracker);
            $made[(int) $source->getId()] = $tracker;
        }

        foreach ($this->rules->findByTemplate($template) as $source) {
            try {
                $definition = $source->getDefinition();
            } catch (AutomationException $e) {
                $this->logger->warning('The rule "{rule}" of the template "{template}" can no longer be read: project {project} was created without it.', ['rule' => $source->getName(), 'template' => $template->getName(), 'project' => $project->getIssuePrefix(), 'exception' => $e]);
                continue;
            }
            $rule = new AutomationRule($project, $source->getName(), null === $source->getTracker() ? null : $made[(int) $source->getTracker()->getId()], $definition);
            if (!$source->isActive()) {
                $rule->deactivate();
            }
            $this->entityManager->persist($rule);
        }
        // The broadest role wins: a team that holds as much on the workspace already is given nothing more.
        $definition = $this->config->definition();
        foreach ($this->teamAccesses->findByTemplate($template) as $source) {
            $inherited = $this->accesses->findOne($source->getTeam(), $project->getWorkspace());
            if (null !== $inherited && $definition->roleRank($source->getRole()) >= $definition->roleRank($inherited->getRole())) {
                continue;
            }
            $this->entityManager->persist(TeamAccess::onProject($source->getTeam(), $project, $this->config->row(Role::class, $source->getRole())));
        }
    }
}
