<?php

declare(strict_types=1);

namespace App\Tests\Config\Fixture;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\FieldType;
use App\Config\TransitionStyle;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Place;
use App\Entity\Role;
use App\Entity\Workflow;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Small, valid definitions for tests. Every piece can be replaced.
 */
final class DefinitionFactory
{
    /**
     * @return list<RoleDefinition>
     */
    public static function roles(): array
    {
        return [
            // The manager moves issues freely; the developer and the client have the actions of the workflow.
            new RoleDefinition(id: 'project_manager', label: 'Project manager', internalOnly: true, followsNewIssues: true, freeWorkflow: true),
            new RoleDefinition(id: 'developer', label: 'Developer', internalOnly: true),
            new RoleDefinition(id: 'client', label: 'Client'),
        ];
    }

    /**
     * @return list<FieldDefinition>
     */
    public static function fields(): array
    {
        return [
            new FieldDefinition(id: 'url', label: 'URL', type: FieldType::Url),
            new FieldDefinition(id: 'environment', label: 'Environment', type: FieldType::Select, options: ['prod' => 'Production', 'preprod' => 'Pre-production']),
        ];
    }

    /**
     * The places the workflows of the tests are made of, in the order of the instance.
     *
     * @return list<PlaceDefinition>
     */
    public static function places(): array
    {
        return [
            new PlaceDefinition(id: 'new', label: 'New', status: PlaceStatus::Open),
            new PlaceDefinition(id: 'in_progress', label: 'In progress', status: PlaceStatus::InProgress),
            new PlaceDefinition(id: 'to_test', label: 'To test', status: PlaceStatus::Waiting),
            new PlaceDefinition(id: 'done', label: 'Closed', status: PlaceStatus::Closed),
        ];
    }

    /**
     * The places a set of workflows use: those of places() in their order,
     * then an open place for every other id, so that a test may write a
     * workflow without declaring its places apart.
     *
     * @param list<string> $ids
     *
     * @return list<PlaceDefinition>
     */
    public static function placesFor(array $ids): array
    {
        $known = array_values(array_filter(self::places(), static fn (PlaceDefinition $place): bool => \in_array($place->id, $ids, true)));
        $others = array_diff(array_unique($ids), array_map(static fn (PlaceDefinition $place): string => $place->id, $known));

        return [...$known, ...array_map(static fn (string $id): PlaceDefinition => new PlaceDefinition(id: $id, label: strtoupper($id), status: PlaceStatus::Open), array_values($others))];
    }

    public static function place(string $id): PlaceDefinition
    {
        return array_find(self::places(), static fn (PlaceDefinition $place): bool => $place->id === $id) ?? throw new \LogicException(\sprintf('No place "%s" in the test definitions.', $id));
    }

    /**
     * A bug workflow: new → in_progress → to_test → done, with to_test optional.
     */
    public static function bugWorkflow(bool $withToTest = true, string $label = 'Bug'): WorkflowDefinition
    {
        $transitions = [
            new TransitionDefinition(id: 'start', label: 'Start', from: ['new'], to: 'in_progress', roles: ['developer']),
        ];

        if ($withToTest) {
            $transitions[] = new TransitionDefinition(id: 'deliver', label: 'Deliver', from: ['in_progress'], to: 'to_test', roles: ['developer']);
            $transitions[] = new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'done', roles: ['client'], style: TransitionStyle::Primary, description: 'The fix is right.');
            $transitions[] = new TransitionDefinition(id: 'refuse', label: 'Refuse', from: ['to_test'], to: 'in_progress', roles: ['client'], style: TransitionStyle::Danger, requiresComment: true, commentPrompt: 'Why?');
        } else {
            $transitions[] = new TransitionDefinition(id: 'finish', label: 'Finish', from: ['in_progress'], to: 'done', roles: ['developer']);
        }

        return new WorkflowDefinition(
            id: 'bug',
            label: $label,
            initial: 'new',
            places: $withToTest ? ['new', 'in_progress', 'to_test', 'done'] : ['new', 'in_progress', 'done'],
            transitions: $transitions,
            fields: [new FieldUsage('environment', required: true), new FieldUsage('url')],
        );
    }

    /**
     * @param list<WorkflowDefinition>|null    $workflows
     * @param list<RoleDefinition>|null        $roles
     * @param list<FieldDefinition>|null       $fields
     * @param array<string, list<string>>|null $permissions
     * @param list<LinkTypeDefinition>|null    $links
     * @param list<PlaceDefinition>|null       $places      those the workflows use by default
     */
    public static function definition(
        ?array $workflows = null,
        ?array $roles = null,
        ?array $fields = null,
        ?array $permissions = null,
        ?array $links = null,
        ?array $places = null,
    ): InstanceDefinition {
        $workflows ??= [self::bugWorkflow()];
        $used = array_merge(...array_map(static fn (WorkflowDefinition $workflow): array => $workflow->places, $workflows));

        return new InstanceDefinition(
            roles: $roles ?? self::roles(),
            permissions: $permissions ?? [
                Permission::IssueCreate->value => ['project_manager', 'developer', 'client'],
                Permission::IssueEdit->value => ['project_manager'],
                Permission::IssueAssign->value => ['project_manager', 'developer'],
                Permission::IssueRank->value => ['project_manager', 'developer'],
                Permission::IssueSetCriticality->value => ['project_manager', 'developer', 'client'],
                Permission::IssueSetPrivate->value => ['project_manager'],
                Permission::IssueSetRelease->value => ['project_manager', 'developer'],
                Permission::IssueSetTags->value => ['project_manager', 'developer', 'client'],
                Permission::IssueLink->value => ['project_manager', 'developer'],
                Permission::IssueDelete->value => ['project_manager', 'developer'],
                Permission::CommentCreate->value => ['project_manager', 'developer', 'client'],
                Permission::CommentSetPrivate->value => ['project_manager', 'developer'],
                Permission::CommentEdit->value => ['project_manager'],
                Permission::ProjectConfigure->value => ['project_manager'],
                Permission::TrackerManage->value => ['project_manager'],
                Permission::TagManage->value => ['project_manager'],
                Permission::AutomationManage->value => ['project_manager'],
                Permission::RepositoryManage->value => ['project_manager'],
                Permission::ProjectManageMembers->value => ['project_manager'],
                Permission::ReleaseManage->value => ['project_manager'],
            ],
            fields: $fields ?? self::fields(),
            links: $links ?? [
                new LinkTypeDefinition(id: 'related', label: 'Related to'),
                new LinkTypeDefinition(id: 'blocks', label: 'Blocks', inverseLabel: 'Blocked by'),
            ],
            places: $places ?? self::placesFor($used),
            workflows: $workflows,
        );
    }

    /**
     * The row of a workflow, for a tracker a test builds by hand. A tracker
     * that is persisted must name a row the entity manager knows: give it,
     * and the row is the one of the installed configuration. Without it
     * the row is a detached one, enough for what is never written.
     */
    public static function workflowRow(string $id = 'bug', ?EntityManagerInterface $entityManager = null): Workflow
    {
        return $entityManager?->getReference(Workflow::class, $id) ?? new Workflow($id, ucfirst($id), new Place('new', 'New', PlaceStatus::Open));
    }

    /**
     * The row of a role, for an access a test builds by hand: as for a workflow.
     */
    public static function roleRow(string $id, ?EntityManagerInterface $entityManager = null): Role
    {
        return $entityManager?->getReference(Role::class, $id) ?? new Role($id, ucfirst($id));
    }

    /**
     * The row of a place, for an issue a test builds or moves by hand: as
     * for a workflow, with the status the place of the tests has.
     */
    public static function placeRow(string $id, ?EntityManagerInterface $entityManager = null): Place
    {
        return $entityManager?->getReference(Place::class, $id) ?? new Place($id, self::place($id)->label, self::place($id)->status);
    }
}
