<?php

declare(strict_types=1);

namespace App\Automation\Definition;

use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\WorkflowDefinition;

/**
 * Where a rule is written, and so what it may name: the trackers it can
 * be limited to, each with its workflow, the roles whose referent it can
 * aim at, and the people and teams it can name. A project gives one
 * (RuleContexts::ofProject()); a project template gives its own
 * (ofTemplate()), without people. The checker and the form read nothing
 * else, so a rule is edited the same way in both.
 */
final readonly class RuleContext
{
    /**
     * @param array<array-key, string>             $trackers  tracker key => name (a tracker id reads as an integer key)
     * @param array<array-key, WorkflowDefinition> $workflows tracker key => its workflow
     * @param array<string, PlaceDefinition>       $places    those of the instance, by id, in its order
     * @param array<string, string>                $roles     role id => label
     * @param array<int, string>                   $people    user id => full name; empty where nobody can be named
     * @param array<int, string>                   $teams     team id => name: those a rule may aim at
     * @param list<int>                            $idleTeams those of them that hold no role on the project: a rule already names them, and what it gives them is skipped until they do
     */
    public function __construct(
        public array $trackers,
        public array $workflows,
        public array $places,
        public array $roles,
        public array $people = [],
        public array $teams = [],
        public array $idleTeams = [],
    ) {
    }

    /**
     * The workflow of the tracker a rule is limited to, null without one.
     */
    public function workflow(?string $tracker): ?WorkflowDefinition
    {
        return null === $tracker ? null : $this->workflows[$tracker] ?? null;
    }

    /**
     * The places a rule may meet, in the order of the instance: those of
     * the workflow of the tracker it is limited to, or of every tracker
     * without one. A place is the same wherever it is used, so a rule that
     * names one holds on every tracker that has it.
     *
     * @param string|null $tracker the key of the tracker the rule is limited to
     *
     * @return array<string, string> place id => label
     */
    public function places(?string $tracker): array
    {
        $used = [];
        foreach (null === $tracker ? $this->workflows : array_filter([$this->workflow($tracker)]) as $workflow) {
            $used += array_flip($workflow->places);
        }

        return array_map(static fn (PlaceDefinition $place): string => $place->label, array_intersect_key($this->places, $used));
    }

    /**
     * The places of the workflow of the tracker a rule is limited to, in
     * the order of that workflow; none without a tracker.
     *
     * @return array<string, string> place id => label
     */
    public function placesOf(?string $tracker): array
    {
        $places = [];
        foreach ($this->workflow($tracker)->places ?? [] as $placeId) {
            if (isset($this->places[$placeId])) {
                $places[$placeId] = $this->places[$placeId]->label;
            }
        }

        return $places;
    }
}
