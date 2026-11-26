<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\Target;
use App\Entity\AutomationRule;
use App\Entity\Project;
use App\Entity\Team;
use App\Message\Query\Project\ListProjectTeams;
use App\Message\QueryBus;
use App\Repository\TeamRepository;

/**
 * The teams the active rules of a project aim at while they hold no role
 * on it: a rule copied from a template names a team of the instance,
 * whose access to the project is granted apart. What those rules say they
 * will do is skipped until then, which the page of the rules says before
 * an execution does, as for a role without a referent (MissingReferents).
 */
final class TeamsWithoutAccess
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly QueryBus $queries,
    ) {
    }

    /**
     * @param list<AutomationRule> $rules
     * @param bool                 $activeOnly leaves out the rules switched off, which do nothing anyway; the form of a rule reads them all
     *
     * @return array<int, string> team id => name
     */
    public function of(Project $project, array $rules, bool $activeOnly = true): array
    {
        $onProject = array_map(static fn (Team $team): ?int => $team->getId(), $this->queries->ask(new ListProjectTeams($project)));
        $missing = [];
        foreach ($rules as $rule) {
            if ($activeOnly && !$rule->isActive()) {
                continue;
            }
            try {
                $actions = $rule->getDefinition()->actions;
            } catch (AutomationException) {
                continue;
            }
            foreach ($actions as $action) {
                $target = Target::parse($action->params['target'] ?? null);
                if (Target::TEAM !== $target?->kind || \in_array((int) $target->argument, $onProject, true)) {
                    continue;
                }
                $team = $this->teams->find((int) $target->argument);
                if (null !== $team) {
                    $missing[(int) $team->getId()] = $team->getName();
                }
            }
        }

        return $missing;
    }
}
