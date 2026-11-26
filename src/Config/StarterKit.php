<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Entity\ProjectTemplate;
use App\Entity\TemplateTracker;
use App\Message\Command\Config\InstallConfiguration;
use App\Message\Command\Template\CreateTemplate;
use App\Message\Command\Template\CreateTemplateRule;
use App\Message\Command\Template\CreateTemplateTracker;
use App\Message\CommandBus;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a new instance starts with: the roles, permissions, steps,
 * workflows, fields and link types of a team working with its clients,
 * and a project template to create the first project with. Installed once, into an instance that has no configuration,
 * and never applied again: what it gave is then data like any other,
 * changed from the administration, and a later version of the kit changes
 * no instance that exists.
 *
 * The ids are the same in every language; the labels come from the
 * `starter` translation catalogue, read in the language of the instance
 * when the kit is installed.
 */
final class StarterKit
{
    private const array INTERNAL = ['project_manager', 'developer'];
    private const array INTERNAL_AND_CLIENT = ['project_manager', 'developer', 'client', 'partner'];
    /** The roles held to the workflow: the actions are theirs. */
    private const array CLIENT_FACING = ['client', 'partner'];
    private const array MANAGERS = ['project_manager'];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ConfigRegistry $registry,
        private readonly CommandBus $commands,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Installs the kit, unless the instance already has a configuration.
     * The configuration and its template come together or not at all:
     * each command has its transaction, and this one holds them all, so a
     * template that cannot be made leaves an instance still to install.
     *
     * @return bool false when there was a configuration, left as it is
     */
    public function install(): bool
    {
        if (!$this->registry->definition()->isEmpty()) {
            return false;
        }

        $definition = $this->definition();
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->commands->dispatch(new InstallConfiguration($definition));
            $this->installTemplate($definition);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            // What was written was taken back: neither the entity manager nor the registry may go on holding it.
            $this->entityManager->clear();
            $this->registry->reset();

            throw $e;
        }

        return true;
    }

    /**
     * What the automation rules of a project of the kit say: the name of
     * each, whether it is limited to the bugs, its definition, whether it
     * is switched on. They aim at the referent of a role, never at a
     * person, so that a project template may carry them.
     *
     * @return list<array{string, bool, array<string, mixed>, bool}>
     */
    public function rules(): array
    {
        $toTheManager = [['type' => 'assign', 'params' => ['target' => 'referent:project_manager']]];
        $leftWithTheClient = [['type' => 'assignee', 'params' => ['is' => 'external']], ['type' => 'code', 'params' => ['is' => 'none']]];

        return [
            [$this->label('template.rule.to_manager'), false, [
                'trigger' => ['type' => 'issue_created'],
                'conditions' => [['type' => 'assignee', 'params' => ['is' => 'nobody']]],
                'actions' => $toTheManager,
            ], true],
            [$this->label('template.rule.client_answers'), false, [
                'trigger' => ['type' => 'comment_added'],
                'conditions' => [['type' => 'actor', 'params' => ['is' => 'external']], ['type' => 'assignee', 'params' => ['is' => 'external_or_nobody']]],
                'actions' => $toTheManager,
            ], true],
            [$this->label('template.rule.reminder'), false, [
                'trigger' => ['type' => 'inactive_for', 'params' => ['days' => 30]],
                'conditions' => $leftWithTheClient,
                'actions' => [['type' => 'comment', 'params' => ['body' => $this->label('template.rule.reminder_body')]]],
            ], true],
            [$this->label('template.rule.closing'), true, [
                'trigger' => ['type' => 'inactive_for', 'params' => ['days' => 45]],
                'conditions' => $leftWithTheClient,
                'actions' => [['type' => 'move', 'params' => ['place' => 'done']]],
            ], false],
        ];
    }

    /**
     * The project template of the kit: a tracker per workflow and the
     * rules, for the first project to start with.
     */
    private function installTemplate(InstanceDefinition $definition): void
    {
        $template = $this->commands->dispatch(new CreateTemplate($this->label('template.name'), $this->label('template.description')));
        \assert($template instanceof ProjectTemplate);
        $templateId = (int) $template->getId();

        $bugs = $this->commands->dispatch(new CreateTemplateTracker($templateId, $definition->workflow('bug')->label, 'bug', $this->label('template.tracker.bug')));
        \assert($bugs instanceof TemplateTracker);
        $this->commands->dispatch(new CreateTemplateTracker($templateId, $definition->workflow('evolution')->label, 'evolution', $this->label('template.tracker.evolution')));
        $this->commands->dispatch(new CreateTemplateTracker($templateId, $definition->workflow('task')->label, 'task', $this->label('template.tracker.task'), private: true));

        foreach ($this->rules() as [$name, $onBugs, $rule, $active]) {
            $this->commands->dispatch(new CreateTemplateRule($templateId, $name, $onBugs ? (int) $bugs->getId() : null, $rule, $active));
        }
    }

    public function definition(): InstanceDefinition
    {
        return new InstanceDefinition(
            // Broadest role first: a direct grant on a project may only widen a
            // role inherited from the workspace. Internal users without a role
            // see everything but act nowhere. The internal roles move issues
            // freely; the others have the actions of the workflows.
            roles: [
                new RoleDefinition('project_manager', $this->label('role.project_manager'), internalOnly: true, followsNewIssues: true, freeWorkflow: true),
                new RoleDefinition('developer', $this->label('role.developer'), internalOnly: true, freeWorkflow: true),
                new RoleDefinition('client', $this->label('role.client')),
                new RoleDefinition('partner', $this->label('role.partner')),
            ],

            // What a role does besides moving issues. Administrators hold all of them.
            permissions: [
                Permission::IssueCreate->value => self::INTERNAL_AND_CLIENT,
                // Authors always edit their own issues and comments; these two
                // grant editing the content written by others.
                Permission::IssueEdit->value => self::MANAGERS,
                Permission::IssueDelete->value => self::INTERNAL,
                Permission::IssueAssign->value => self::INTERNAL,
                // The order of a kanban column is shared: a client role may be given it to order its backlog.
                Permission::IssueRank->value => self::INTERNAL,
                Permission::IssueSetCriticality->value => self::INTERNAL_AND_CLIENT,
                Permission::IssueSetPrivate->value => self::INTERNAL,
                Permission::IssueSetRelease->value => self::INTERNAL,
                Permission::IssueSetTags->value => self::INTERNAL_AND_CLIENT,
                Permission::IssueLink->value => self::INTERNAL,
                Permission::CommentCreate->value => self::INTERNAL_AND_CLIENT,
                Permission::CommentSetPrivate->value => self::INTERNAL,
                Permission::CommentEdit->value => self::MANAGERS,
                // The configuration of a project, a permission per subject: what is technical goes to the developers too.
                Permission::ProjectConfigure->value => self::MANAGERS,
                Permission::TrackerManage->value => self::MANAGERS,
                Permission::TagManage->value => self::INTERNAL,
                Permission::AutomationManage->value => self::MANAGERS,
                Permission::RepositoryManage->value => self::INTERNAL,
                Permission::ProjectManageMembers->value => self::MANAGERS,
                Permission::ReleaseManage->value => self::MANAGERS,
                Permission::BudgetManage->value => self::MANAGERS,
                Permission::BudgetView->value => ['project_manager', 'developer', 'client'],
            ],

            // An estimate is not a field: it is a line of a budget of the project.
            fields: [
                $this->field('url', FieldType::Url, placeholder: true, help: true),
                $this->field('due_date', FieldType::Date),
            ],

            // Without an inverse label the link reads the same from both sides.
            links: [
                new LinkTypeDefinition('related', $this->label('link.related.label')),
                new LinkTypeDefinition('duplicates', $this->label('link.duplicates.label'), $this->label('link.duplicates.inverse')),
                new LinkTypeDefinition('blocks', $this->label('link.blocks.label'), $this->label('link.blocks.inverse')),
            ],

            // This order is the order of the filters and of the groups of the issue lists.
            places: [
                $this->place('new', PlaceStatus::Open),
                $this->place('needs_info', PlaceStatus::Waiting),
                $this->place('to_do', PlaceStatus::Open),
                $this->place('feedback', PlaceStatus::Waiting),
                $this->place('to_estimate', PlaceStatus::Open),
                $this->place('to_approve', PlaceStatus::Waiting),
                $this->place('planned', PlaceStatus::InProgress),
                $this->place('in_progress', PlaceStatus::InProgress),
                $this->place('blocked', PlaceStatus::Waiting),
                $this->place('to_test', PlaceStatus::Waiting),
                $this->place('to_release', PlaceStatus::InProgress),
                $this->place('done', PlaceStatus::Closed),
                $this->place('declined', PlaceStatus::Closed),
            ],

            // An action is what a role held to the workflow may do: the team moves its issues freely, step by step.
            // The diagram of a workflow links its places in their order; those named `aside` hang from the place of that path they stand beside.
            workflows: [
                new WorkflowDefinition(
                    id: 'bug',
                    label: $this->label('workflow.bug.label'),
                    initial: 'new',
                    places: ['new', 'feedback', 'needs_info', 'to_do', 'blocked', 'in_progress', 'to_test', 'to_release', 'done', 'declined'],
                    transitions: [
                        $this->transition('bug', 'provide_info', from: ['needs_info'], to: 'new', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('bug', 'acknowledge', from: ['feedback'], to: 'done', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('bug', 'insist', from: ['feedback'], to: 'new', roles: self::CLIENT_FACING, requiresComment: true),
                        $this->transition('bug', 'accept', from: ['to_test'], to: 'to_release', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('bug', 'refuse', from: ['to_test'], to: 'in_progress', roles: self::CLIENT_FACING, style: TransitionStyle::Danger, requiresComment: true),
                    ],
                    fields: [new FieldUsage('url')],
                    aside: ['feedback' => 'new', 'needs_info' => 'new', 'blocked' => 'to_do', 'declined' => 'new'],
                ),
                new WorkflowDefinition(
                    id: 'evolution',
                    label: $this->label('workflow.evolution.label'),
                    initial: 'new',
                    places: ['new', 'feedback', 'to_estimate', 'to_approve', 'planned', 'blocked', 'in_progress', 'to_test', 'to_release', 'done', 'declined'],
                    transitions: [
                        $this->transition('evolution', 'acknowledge', from: ['feedback'], to: 'done', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('evolution', 'insist', from: ['feedback'], to: 'new', roles: self::CLIENT_FACING, requiresComment: true),
                        $this->transition('evolution', 'approve', from: ['to_approve'], to: 'planned', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('evolution', 'decline_estimate', from: ['to_approve'], to: 'declined', roles: self::CLIENT_FACING, style: TransitionStyle::Danger),
                        $this->transition('evolution', 'accept', from: ['to_test'], to: 'to_release', roles: self::CLIENT_FACING, style: TransitionStyle::Primary),
                        $this->transition('evolution', 'refuse', from: ['to_test'], to: 'in_progress', roles: self::CLIENT_FACING, style: TransitionStyle::Danger, requiresComment: true),
                    ],
                    fields: [new FieldUsage('due_date')],
                    aside: ['feedback' => 'new', 'blocked' => 'planned', 'declined' => 'new'],
                ),
                // Internal tasks: the team moves them itself, without an action.
                new WorkflowDefinition(
                    id: 'task',
                    label: $this->label('workflow.task.label'),
                    initial: 'new',
                    places: ['new', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
                    transitions: [],
                    fields: [new FieldUsage('due_date')],
                    aside: ['blocked' => 'in_progress', 'declined' => 'new'],
                ),
            ],
        );
    }

    private function place(string $id, PlaceStatus $status): PlaceDefinition
    {
        return new PlaceDefinition($id, $this->label('place.'.$id), $status);
    }

    /**
     * @param list<string> $options the values of a choice, whose labels the catalogue gives
     */
    private function field(string $id, FieldType $type, array $options = [], bool $placeholder = false, bool $help = false): FieldDefinition
    {
        $labels = [];
        foreach ($options as $value) {
            $labels[$value] = $this->label("field.$id.option.$value");
        }

        return new FieldDefinition(
            id: $id,
            label: $this->label("field.$id.label"),
            type: $type,
            options: $labels,
            placeholder: $placeholder ? $this->label("field.$id.placeholder") : null,
            help: $help ? $this->label("field.$id.help") : null,
        );
    }

    /**
     * An action of a workflow: its label and what it does come from the
     * catalogue, and the prompt of its comment when it requires one.
     *
     * @param list<string> $from
     * @param list<string> $roles
     */
    private function transition(string $workflow, string $id, array $from, string $to, array $roles, TransitionStyle $style = TransitionStyle::Default, bool $requiresComment = false): TransitionDefinition
    {
        $key = "workflow.$workflow.transition.$id";

        return new TransitionDefinition(
            id: $id,
            label: $this->label($key.'.label'),
            from: $from,
            to: $to,
            roles: $roles,
            style: $style,
            requiresComment: $requiresComment,
            commentPrompt: $requiresComment ? $this->label($key.'.prompt') : null,
            description: $this->label($key.'.description'),
        );
    }

    private function label(string $key): string
    {
        return $this->translator->trans($key, [], 'starter');
    }
}
