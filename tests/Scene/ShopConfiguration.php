<?php

declare(strict_types=1);

namespace App\Tests\Scene;

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
use App\Security\Permission;

/**
 * The configuration of the shop scene: an agency running bugs, features
 * and internal tasks for its clients. It belongs to the tests and never
 * follows the starter configuration, which is product content tested on
 * its own (StarterKitTest). It shows what the screens must handle: a
 * required choice (`environment`), a required text (`goal`), a field for
 * internal users only (`estimate`), a workflow without fields (tasks) and
 * a role that acts nowhere (`client_readonly`).
 *
 * A test that needs another case adds it through the configuration
 * commands, after the scene is loaded.
 */
final class ShopConfiguration
{
    public static function definition(): InstanceDefinition
    {
        return new InstanceDefinition(
            roles: self::roles(),
            permissions: self::permissions(),
            fields: self::fields(),
            links: self::links(),
            places: self::places(),
            workflows: [self::bug(), self::evolution(), self::task()],
        );
    }

    /**
     * @return list<RoleDefinition>
     */
    private static function roles(): array
    {
        return [
            // The manager moves issues freely; the developer and the client have the actions of the workflows.
            new RoleDefinition(id: 'project_manager', label: 'Project manager', internalOnly: true, followsNewIssues: true, freeWorkflow: true),
            new RoleDefinition(id: 'developer', label: 'Developer', internalOnly: true),
            new RoleDefinition(id: 'client', label: 'Client'),
            new RoleDefinition(id: 'client_readonly', label: 'Client (read-only)'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function permissions(): array
    {
        return [
            Permission::IssueCreate->value => ['project_manager', 'developer', 'client'],
            Permission::IssueEdit->value => ['project_manager'],
            Permission::IssueDelete->value => ['project_manager', 'developer'],
            Permission::IssueAssign->value => ['project_manager', 'developer'],
            Permission::IssueRank->value => ['project_manager', 'developer'],
            Permission::IssueSetCriticality->value => ['project_manager', 'developer', 'client'],
            Permission::IssueSetPrivate->value => ['project_manager', 'developer'],
            Permission::IssueSetRelease->value => ['project_manager', 'developer'],
            Permission::IssueSetTags->value => ['project_manager', 'developer', 'client'],
            Permission::IssueLink->value => ['project_manager', 'developer'],
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
            Permission::BudgetManage->value => ['project_manager'],
            Permission::BudgetView->value => ['project_manager', 'developer', 'client', 'client_readonly'],
        ];
    }

    /**
     * @return list<FieldDefinition>
     */
    private static function fields(): array
    {
        return [
            new FieldDefinition(id: 'environment', label: 'Environment', type: FieldType::Choice, options: ['prod' => 'Production', 'preprod' => 'Pre-production']),
            new FieldDefinition(id: 'url', label: 'Affected URL', type: FieldType::Url, placeholder: 'https://…', help: 'The URL where the problem occurs.'),
            new FieldDefinition(id: 'steps', label: 'Steps to reproduce', type: FieldType::Textarea, placeholder: '1. I go to… 2. I click on… 3. I see…', help: 'What you did, what you expected, what happened.'),
            new FieldDefinition(id: 'browser', label: 'Context', type: FieldType::Text, placeholder: 'Chrome 130 on macOS, Safari on iPhone, account used…'),
            new FieldDefinition(id: 'goal', label: 'Goal', type: FieldType::Textarea, placeholder: 'Which problem does this feature solve, and for whom?', help: 'The need rather than the solution: it helps us offer the right answer.'),
            new FieldDefinition(id: 'due_date', label: 'Desired due date', type: FieldType::Date),
            new FieldDefinition(id: 'estimate', label: 'Estimate (days)', type: FieldType::Number, internalOnly: true),
        ];
    }

    /**
     * @return list<LinkTypeDefinition>
     */
    private static function links(): array
    {
        return [
            new LinkTypeDefinition(id: 'related', label: 'Related to'),
            new LinkTypeDefinition(id: 'duplicates', label: 'Duplicates', inverseLabel: 'Duplicated by'),
            new LinkTypeDefinition(id: 'blocks', label: 'Blocks', inverseLabel: 'Blocked by'),
        ];
    }

    /**
     * @return list<PlaceDefinition>
     */
    private static function places(): array
    {
        return [
            new PlaceDefinition(id: 'new', label: 'New', status: PlaceStatus::Open),
            new PlaceDefinition(id: 'needs_info', label: 'Info requested', status: PlaceStatus::Waiting),
            new PlaceDefinition(id: 'qualified', label: 'Qualified', status: PlaceStatus::Open),
            new PlaceDefinition(id: 'to_estimate', label: 'To estimate', status: PlaceStatus::Open),
            new PlaceDefinition(id: 'to_approve', label: 'Estimate to approve', status: PlaceStatus::Waiting),
            new PlaceDefinition(id: 'planned', label: 'Planned', status: PlaceStatus::InProgress),
            new PlaceDefinition(id: 'in_progress', label: 'In progress', status: PlaceStatus::InProgress),
            new PlaceDefinition(id: 'blocked', label: 'Blocked', status: PlaceStatus::Waiting),
            new PlaceDefinition(id: 'to_test', label: 'To test', status: PlaceStatus::Waiting),
            new PlaceDefinition(id: 'to_release', label: 'To release', status: PlaceStatus::InProgress),
            new PlaceDefinition(id: 'done', label: 'Done', status: PlaceStatus::Closed),
            new PlaceDefinition(id: 'declined', label: 'Declined', status: PlaceStatus::Closed),
        ];
    }

    private static function bug(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            id: 'bug',
            label: 'Bug',
            initial: 'new',
            places: ['new', 'needs_info', 'qualified', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
            transitions: [
                new TransitionDefinition(id: 'ask_info', label: 'Ask for details', from: ['new'], to: 'needs_info', roles: ['developer'], requiresComment: true, commentPrompt: 'Say what we are missing to reproduce the problem.', description: 'Something is missing to understand the bug: the issue waits for the client\'s answer.'),
                new TransitionDefinition(id: 'provide_info', label: 'Answer', from: ['needs_info'], to: 'new', roles: ['developer', 'client'], style: TransitionStyle::Primary, description: 'The details are given: the issue goes back to the team.'),
                new TransitionDefinition(id: 'qualify', label: 'Qualify', from: ['new'], to: 'qualified', roles: ['developer'], description: 'The bug is understood and reproduced: it joins the team\'s queue.'),
                new TransitionDefinition(id: 'reject', label: 'Reject', from: ['new', 'needs_info', 'qualified'], to: 'declined', roles: ['developer'], style: TransitionStyle::Danger, description: 'It is not a bug, or it will not be fixed: the issue is closed.'),
                new TransitionDefinition(id: 'start', label: 'Start', from: ['new', 'qualified'], to: 'in_progress', roles: ['developer'], description: 'The fix begins.'),
                new TransitionDefinition(id: 'block', label: 'Block', from: ['in_progress'], to: 'blocked', roles: ['developer'], requiresComment: true, commentPrompt: 'Say what is blocking and who must lift it.', description: 'The fix cannot move forward: it depends on a third party or a prerequisite.'),
                new TransitionDefinition(id: 'resume', label: 'Resume', from: ['blocked'], to: 'in_progress', roles: ['developer'], description: 'The blocker is lifted: the fix resumes.'),
                new TransitionDefinition(id: 'deliver', label: 'Deliver for testing', from: ['in_progress'], to: 'to_test', roles: ['developer'], description: 'The fix is on pre-production, ready to be checked.'),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'to_release', roles: ['client'], style: TransitionStyle::Primary, description: 'The fix is accepted: it waits for its release to production.'),
                new TransitionDefinition(id: 'refuse', label: 'Refuse', from: ['to_test'], to: 'in_progress', roles: ['client'], style: TransitionStyle::Danger, requiresComment: true, commentPrompt: 'Explain what is wrong: a comment is required to refuse.', description: 'The fix is not right: the issue goes back to development, with the comment that says why.'),
                new TransitionDefinition(id: 'release', label: 'Release to production', from: ['to_release'], to: 'done', roles: ['developer'], style: TransitionStyle::Primary, description: 'The fix is in production: the issue is closed. It can be reopened if the problem comes back.'),
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['done'], to: 'in_progress', roles: ['client'], description: 'The problem came back: the issue goes back to development.'),
                new TransitionDefinition(id: 'reopen_rejected', label: 'Reopen', from: ['declined'], to: 'new', roles: ['developer'], description: 'The issue is reviewed again from the start.'),
            ],
            fields: [new FieldUsage('environment', required: true), new FieldUsage('url'), new FieldUsage('steps'), new FieldUsage('browser'), new FieldUsage('estimate')],
            aside: ['needs_info' => 'new', 'blocked' => 'in_progress', 'declined' => 'new'],
        );
    }

    private static function evolution(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            id: 'evolution',
            label: 'Feature',
            initial: 'new',
            places: ['new', 'to_estimate', 'to_approve', 'planned', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
            transitions: [
                new TransitionDefinition(id: 'estimate', label: 'To estimate', from: ['new'], to: 'to_estimate', roles: ['developer'], description: 'The request is understood: it goes to estimation.'),
                new TransitionDefinition(id: 'decline', label: 'Decline', from: ['new', 'to_estimate'], to: 'declined', roles: ['developer'], style: TransitionStyle::Danger, description: 'The feature will not be built: the issue is closed.'),
                new TransitionDefinition(id: 'submit_estimate', label: 'Submit the estimate', from: ['to_estimate'], to: 'to_approve', roles: ['developer'], description: 'The estimate was sent to the client: the issue waits for their decision.'),
                new TransitionDefinition(id: 'approve', label: 'Accept the estimate', from: ['to_approve'], to: 'planned', roles: ['client'], style: TransitionStyle::Primary, description: 'The estimate is accepted: the feature is planned.'),
                new TransitionDefinition(id: 'decline_estimate', label: 'Refuse the estimate', from: ['to_approve'], to: 'declined', roles: ['client'], style: TransitionStyle::Danger, requiresComment: true, commentPrompt: 'A comment is required to refuse the estimate.', description: 'The estimate is refused: the feature will not be built and the issue is closed.'),
                new TransitionDefinition(id: 'unplan', label: 'Unplan', from: ['planned'], to: 'to_estimate', roles: ['developer'], description: 'The plan is called into question: the feature goes back to estimation.'),
                new TransitionDefinition(id: 'start', label: 'Start', from: ['planned'], to: 'in_progress', roles: ['developer'], description: 'The work begins.'),
                new TransitionDefinition(id: 'block', label: 'Block', from: ['planned', 'in_progress'], to: 'blocked', roles: ['developer'], requiresComment: true, commentPrompt: 'Say what is blocking and who must lift it.', description: 'The feature cannot move forward: it depends on a third party or a prerequisite.'),
                new TransitionDefinition(id: 'resume', label: 'Resume', from: ['blocked'], to: 'in_progress', roles: ['developer'], description: 'The blocker is lifted: the work resumes.'),
                new TransitionDefinition(id: 'replan', label: 'Replan', from: ['blocked'], to: 'planned', roles: ['developer'], description: 'The blocker is lifted: the feature goes back to the team\'s queue.'),
                new TransitionDefinition(id: 'deliver', label: 'Deliver for testing', from: ['in_progress'], to: 'to_test', roles: ['developer'], description: 'The feature is on pre-production, ready to be checked.'),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'to_release', roles: ['client'], style: TransitionStyle::Primary, description: 'The feature matches the request: it waits for its release to production.'),
                new TransitionDefinition(id: 'refuse', label: 'Refuse', from: ['to_test'], to: 'in_progress', roles: ['client'], style: TransitionStyle::Danger, requiresComment: true, commentPrompt: 'Explain what does not match the request: a comment is required to refuse.', description: 'The feature does not match the request: it goes back to development, with the comment that says why.'),
                new TransitionDefinition(id: 'release', label: 'Release to production', from: ['to_release'], to: 'done', roles: ['developer'], style: TransitionStyle::Primary, description: 'The feature is in production: the issue is closed.'),
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['done'], to: 'in_progress', roles: ['developer'], description: 'The delivered feature needs more work.'),
                new TransitionDefinition(id: 'reopen_declined', label: 'Reopen', from: ['declined'], to: 'new', roles: ['developer'], description: 'The issue is reviewed again from the start.'),
            ],
            fields: [new FieldUsage('goal', required: true), new FieldUsage('due_date')],
        );
    }

    private static function task(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            id: 'task',
            label: 'Internal task',
            initial: 'new',
            places: ['new', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
            transitions: [
                new TransitionDefinition(id: 'start', label: 'Start', from: ['new'], to: 'in_progress', roles: ['developer'], description: 'The task is taken on.'),
                new TransitionDefinition(id: 'block', label: 'Block', from: ['in_progress'], to: 'blocked', roles: ['developer'], requiresComment: true, commentPrompt: 'Say what is blocking and who must lift it.', description: 'The task cannot move forward: it depends on a third party or a prerequisite.'),
                new TransitionDefinition(id: 'resume', label: 'Resume', from: ['blocked'], to: 'in_progress', roles: ['developer'], description: 'The blocker is lifted: the task resumes.'),
                new TransitionDefinition(id: 'deliver', label: 'Deliver for testing', from: ['in_progress'], to: 'to_test', roles: ['developer'], description: 'The task is on pre-production, ready to be checked by the team.'),
                new TransitionDefinition(id: 'accept', label: 'Accept', from: ['to_test'], to: 'to_release', roles: ['developer'], style: TransitionStyle::Primary, description: 'The internal testing passed: the task waits for its release to production.'),
                new TransitionDefinition(id: 'refuse', label: 'Refuse', from: ['to_test'], to: 'in_progress', roles: ['developer'], style: TransitionStyle::Danger, requiresComment: true, commentPrompt: 'Explain what is wrong: a comment is required to refuse.', description: 'The internal testing failed: the task goes back in progress.'),
                new TransitionDefinition(id: 'release', label: 'Release to production', from: ['to_release'], to: 'done', roles: ['developer'], style: TransitionStyle::Primary, description: 'The task is in production: it is done.'),
                new TransitionDefinition(id: 'cancel', label: 'Cancel', from: ['new', 'in_progress', 'blocked'], to: 'declined', roles: ['developer'], style: TransitionStyle::Danger, description: 'The task will not be done.'),
                new TransitionDefinition(id: 'reopen', label: 'Reopen', from: ['done'], to: 'new', roles: ['developer'], description: 'The task is back to do.'),
                new TransitionDefinition(id: 'reopen_cancelled', label: 'Reopen', from: ['declined'], to: 'new', roles: ['developer'], description: 'The task is to do again.'),
            ],
            fields: [],
        );
    }
}
