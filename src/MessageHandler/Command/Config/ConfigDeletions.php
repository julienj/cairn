<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Automation\RuleReferences;
use App\Entity\Field;
use App\Entity\LinkType;
use App\Entity\Place;
use App\Entity\Role;
use App\Entity\Transition;
use App\Entity\Workflow;
use App\Repository\IssueLinkRepository;
use App\Repository\IssueRepository;
use App\Repository\MembershipRepository;
use App\Repository\TeamAccessRepository;
use App\Repository\TemplateTrackerRepository;
use App\Repository\TrackerRepository;
use App\Repository\TransitionRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether something of the configuration may be deleted: only once
 * nothing uses it. The one place that says so, for the handlers that
 * delete, which refuse with what it answers, and for the dialogs, which
 * say why beside a button that cannot be pressed rather than let someone
 * try. Each question is asked of one element, by its row or by its key,
 * and counts only what names that one.
 */
final class ConfigDeletions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipRepository $memberships,
        private readonly TeamAccessRepository $teamAccesses,
        private readonly TransitionRepository $transitions,
        private readonly RuleReferences $rules,
        private readonly WorkflowRepository $workflows,
        private readonly IssueRepository $issues,
        private readonly IssueLinkRepository $links,
        private readonly TrackerRepository $trackers,
        private readonly TemplateTrackerRepository $templateTrackers,
    ) {
    }

    /**
     * A role stays while someone or a team holds it, an action of a workflow names
     * it or an automation rule aims at its referent.
     */
    public function role(Role|string $role): ?DeletionRefusal
    {
        $role = $this->row(Role::class, $role);
        if (null === $role) {
            return null;
        }
        $held = $this->memberships->countHolding($role->getId())['all'] + $this->teamAccesses->countHolding($role->getId())['all'];
        if ($held > 0) {
            return DeletionRefusal::roleHeld($role->getLabel(), $held);
        }
        // Nobody loses an action unseen: the role leaves the actions first.
        $actions = array_map(static fn (Transition $transition): string => \sprintf('%s (%s)', $transition->getLabel(), $transition->getWorkflow()->getLabel()), $this->transitions->findNamingRole($role));
        if ([] !== $actions) {
            return DeletionRefusal::roleTriggersActions($role->getLabel(), $actions);
        }
        $rules = $this->rules->toRole($role->getId());

        return [] === $rules ? null : DeletionRefusal::roleAimedAtByRules($role->getLabel(), $rules);
    }

    /**
     * A step stays while a workflow uses it.
     */
    public function place(Place|string $place): ?DeletionRefusal
    {
        $place = $this->row(Place::class, $place);
        if (null === $place) {
            return null;
        }
        $workflows = $this->workflows->labelsUsingPlace($place);
        if ([] !== $workflows) {
            return DeletionRefusal::placeUsed($place->getLabel(), $workflows);
        }
        // No workflow uses it, so no issue should stand there: checked all the same.
        $standing = $this->issues->countOnPlace($place->getId());

        return 0 === $standing ? null : DeletionRefusal::placeHoldsIssues($place->getLabel(), $standing);
    }

    /**
     * A field stays while a workflow asks for it.
     */
    public function field(Field|string $field): ?DeletionRefusal
    {
        $field = $this->row(Field::class, $field);
        $workflows = null === $field ? [] : $this->workflows->labelsUsingField($field);

        return null === $field || [] === $workflows ? null : DeletionRefusal::fieldUsed($field->getLabel(), $workflows);
    }

    /**
     * Links are never rewritten: a type still in use stays.
     */
    public function linkType(LinkType|string $type): ?DeletionRefusal
    {
        $type = $this->row(LinkType::class, $type);
        $used = null === $type ? 0 : $this->links->count(['type' => $type]);

        return null === $type || 0 === $used ? null : DeletionRefusal::linkTypeUsed($type->getLabel(), $used);
    }

    /**
     * A tracker follows its workflow for good: while one does, of a
     * project or of a template, the workflow stays.
     */
    public function workflow(Workflow|string $workflow): ?DeletionRefusal
    {
        $workflow = $this->row(Workflow::class, $workflow);
        if (null === $workflow) {
            return null;
        }
        $trackers = $this->trackers->count(['workflow' => $workflow]);
        if ($trackers > 0) {
            return DeletionRefusal::workflowFollowed($workflow->getLabel(), $trackers);
        }
        $templates = $this->templateTrackers->templatesUsing($workflow->getId());

        return [] === $templates ? null : DeletionRefusal::workflowInTemplates($workflow->getLabel(), $templates);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param T|string        $row   the row, or its key
     *
     * @return T|null null when the key names nothing: there is nothing to refuse
     */
    private function row(string $class, object|string $row): ?object
    {
        return \is_string($row) ? $this->entityManager->find($class, $row) : $row;
    }
}
