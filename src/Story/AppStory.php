<?php

declare(strict_types=1);

namespace App\Story;

use App\Budget\Quantities;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\StarterKit;
use App\Entity\Attachment;
use App\Entity\Budget;
use App\Entity\CodeRepository;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\BudgetUnit;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\Forge;
use App\Entity\Enum\MergeRequestState;
use App\Entity\Enum\ReleaseStatus;
use App\Entity\Enum\TagColour;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Factory\MembershipFactory;
use App\Factory\ProjectFactory;
use App\Factory\TrackerFactory;
use App\Factory\UserFactory;
use App\Factory\WorkspaceFactory;
use App\Forge\Event\CommitPayload;
use App\Forge\Event\MergeRequestPayload;
use App\Message\Command\Attachment\AddAttachment;
use App\Message\Command\Attachment\UploadAttachment;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Budget\AddBudgetLine;
use App\Message\Command\Budget\CreateBudget;
use App\Message\Command\Forge\LinkRepository;
use App\Message\Command\Forge\RecordCommits;
use App\Message\Command\Forge\RecordMergeRequest;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\AddLink;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Project\UpdateProject;
use App\Message\Command\Release\CreateRelease;
use App\Message\Command\Release\UpdateRelease;
use App\Message\Command\Tag\CreateTag;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Security\AccessControl;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Development data, close to the mockups: four workspaces, their projects
 * and trackers, internal and client users, issues spread over the last
 * two months at every place of the bug, evolution and task workflows, the
 * releases of two projects and a few links, one to an internal task and
 * one across projects.
 *
 * Load it with `castor fixtures`. It installs the starter configuration
 * (Config\StarterKit), whose workflows it is written for, and creates issues through the real
 * commands under a mocked clock, so numbering, journal and dates are
 * consistent. Nothing is drawn at random: the variety (who comments,
 * delays, field values) follows the index of the issue in its project, so
 * that two loads give the same database and tests can rely on it. Every
 * user's password is `cairn`.
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    /** Spread of issue creation dates, in days before now. */
    private const int HISTORY_DAYS = 60;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Team> by name */
    private array $teams = [];

    public function __construct(
        private readonly StarterKit $kit,
        private readonly CommandBus $commands,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
        private readonly EntityManagerInterface $entityManager,
        private readonly Quantities $quantities,
    ) {
    }

    public function build(): void
    {
        // The starter configuration, with the project template every project above could have started with.
        $this->kit->install();

        $this->createUsers();
        $now = (new NativeClock())->now();

        try {
            foreach ($this->projects() as $definition) {
                $project = $definition['project'];
                $trackers = $this->createTrackers($project);
                $dates = self::creationDates($now, \count($definition['issues']));
                $tags = $this->createTags($project, $this->users[$definition['people']['manager']], $definition['tags'] ?? []);
                $issues = [];
                foreach ($definition['issues'] as $index => $issue) {
                    $issues[] = $this->createIssue($project, $trackers[$issue[0]], $issue, $definition['people'], $dates[$index], $now, $index, $tags[$index] ?? []);
                }
                foreach ($definition['releases'] ?? [] as $release) {
                    $this->createRelease($project, $this->users[$definition['people']['manager']], $release, $issues, $now);
                }
                $this->createBudgets($project, $this->users[$definition['people']['manager']], $definition['budgets'] ?? [], $issues, $now);
                if ($definition['archived'] ?? false) {
                    $project->archive();
                } else {
                    $this->createRules($project, $trackers['bug']);
                }
            }
            $this->createLinks($now);
            $this->assignToTeams($now);
            $this->createCode($now);
            $this->entityManager->flush();
        } finally {
            Clock::set(new NativeClock());
        }
    }

    private function createUsers(): void
    {
        $people = [
            'admin' => ['admin@cairn.test', 'Camille', 'Vasseur', 'admin'],
            'thomas' => ['thomas.girard@cairn.test', 'Thomas', 'Girard', 'internal'],
            'yann' => ['yann.leroy@cairn.test', 'Yann', 'Leroy', 'internal'],
            'nadia' => ['nadia.benali@cairn.test', 'Nadia', 'Benali', 'internal'],
            // Signs in through the identity provider of the stack (docker/authelia/), not with a password.
            'lea' => ['lea.fontaine@cairn.test', 'Léa', 'Fontaine', 'sso'],
            'marc' => ['marc.petit@cairn.test', 'Marc', 'Petit', 'internal'],
            'claire' => ['claire.martin@maison-dupont.test', 'Claire', 'Martin', 'external', 'Maison Dupont'],
            'paul' => ['paul.roussel@studio-pixel.test', 'Paul', 'Roussel', 'external', 'Studio Pixel'],
            'ines' => ['ines.garnier@vallier.test', 'Inès', 'Garnier', 'external', 'Vallier'],
            'remi' => ['remi.colin@natura.test', 'Rémi', 'Colin', 'external', 'Natura'],
            'sophie' => ['sophie.bernard@travelia.test', 'Sophie', 'Bernard', 'external', 'Travelia'],
            // Holds no role of her own: she sees Maison Dupont through its accounting team only.
            'lucie' => ['lucie.morel@maison-dupont.test', 'Lucie', 'Morel', 'external', 'Maison Dupont'],
        ];
        foreach ($people as $key => $person) {
            [$email, $firstName, $lastName, $kind] = $person;
            $factory = UserFactory::new(['email' => $email, 'firstName' => $firstName, 'lastName' => $lastName, 'company' => $person[4] ?? null]);
            $this->users[$key] = match ($kind) {
                'admin' => $factory->admin()->create(),
                'internal' => $factory->internal()->create(),
                'sso' => $factory->internal()->sso()->create(),
                default => $factory->create(),
            };
        }
    }

    /**
     * An internal team, which works for two clients, and the team of a
     * client, through which one of its members sees the workspace.
     *
     * @param array<string, Workspace> $workspaces by key
     */
    private function createTeams(array $workspaces): void
    {
        $teams = [
            ['Hosting', true, ['yann', 'marc'], ['MD' => 'developer', 'NC' => 'developer']],
            ['Maison Dupont accounting', false, ['claire', 'lucie'], ['MD' => 'client']],
        ];
        foreach ($teams as [$name, $internal, $members, $accesses]) {
            $team = $this->commands->dispatch(new CreateTeam($name, $internal));
            \assert($team instanceof Team);
            $this->teams[$name] = $team;
            foreach ($members as $member) {
                $this->commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $this->users[$member]->getId()));
            }
            foreach ($accesses as $key => $role) {
                $this->commands->dispatch(new GrantTeamAccess((int) $team->getId(), (int) $workspaces[$key]->getId(), null, $role));
            }
        }
    }

    /**
     * @return iterable<array{project: Project, people: array{manager: string, developers: list<string>, client: ?string}, issues: list<array{0: string, 1: string, 2: string, 3: Criticality, 4?: ?string, 5?: bool}>, tags?: list<array{0: string, 1: TagColour, 2: list<int>}>, releases?: list<array{0: string, 1: int, 2: string, 3: ReleaseStatus, 4: list<int>}>, budgets?: list<array{0: string, 1: int, 2: list<array{0: ?int, 1: ?string, 2: BudgetLineStatus, 3: float|int|null}>}>, archived?: bool}>
     */
    private function projects(): iterable
    {
        // Managers and clients hold their role on the workspace, developers on each project.
        $dupont = $this->workspace('Maison Dupont', 'MD', notes: 'Main contact: Claire Martin (head of e-commerce). Paul Roussel (Studio Pixel) designs the shop as a partner: no access to the budgets.', manager: 'thomas', clients: ['claire' => 'client', 'paul' => 'partner']);
        $vallier = $this->workspace('Vallier Group', 'GV', notes: 'Two projects, a single IT contact.', manager: 'thomas', clients: ['ines' => 'client']);
        $natura = $this->workspace('Natura Cosmetics', 'NC', notes: 'Redesign delivered in November, back office under maintenance.', manager: 'yann', clients: ['remi' => 'client']);
        $travelia = $this->workspace('Travelia', 'TR', notes: 'Contract ended in August 2026.', manager: 'yann', clients: ['sophie' => 'client']);
        $this->createTeams(['MD' => $dupont, 'NC' => $natura]);

        // [tracker, title, target place, criticality, assignee, private]
        yield ['project' => $this->project($dupont, 'Online shop', 'SPT', 'Online shop and checkout.', developers: ['nadia', 'lea']), 'people' => ['manager' => 'thomas', 'developers' => ['nadia', 'lea'], 'client' => 'claire'], 'issues' => [
            ['bug', 'Endless spinner on the cart page', 'new', Criticality::Blocking],
            ['bug', 'The promo code is not applied to discounted products', 'to_do', Criticality::High, 'lea'],
            ['bug', 'Typo in the footer', 'new', Criticality::Low],
            ['bug', 'The CSV export of orders is empty', 'to_test', Criticality::High, 'nadia'],
            ['bug', 'Shipping costs are not shown for Corsica', 'in_progress', Criticality::Normal, 'nadia'],
            ['bug', 'Blurry product image on mobile', 'done', Criticality::Low, 'lea'],
            ['bug', 'Error 500 when validating an address without a number', 'done', Criticality::High, 'nadia'],
            ['bug', 'The size filter ignores sold-out products', 'declined', Criticality::Normal],
            ['evolution', 'Add payment in three instalments', 'to_estimate', Criticality::Normal, 'thomas'],
            ['evolution', 'Monthly accounting export in FEC format', 'in_progress', Criticality::High, 'nadia'],
            ['evolution', 'Search by supplier reference', 'planned', Criticality::Normal, 'lea'],
            ['evolution', 'New home page banner', 'to_test', Criticality::Normal, 'lea'],
            ['evolution', 'Loyalty programme', 'new', Criticality::Low],
            ['evolution', 'Customer reviews on product pages', 'done', Criticality::Normal, 'nadia'],
            ['evolution', 'Shared wish list', 'declined', Criticality::Low],
            ['task', 'Upgrade Symfony to 8.1', 'in_progress', Criticality::Normal, 'nadia'],
            ['task', 'Purge the Varnish cache after deployment', 'done', Criticality::Low, 'lea'],
            ['task', 'Review the indexes of the orders table', 'new', Criticality::Normal],
        ], 'tags' => [
            // [name, colour, indexes of the issues created with it]
            ['Front office', TagColour::Blue, [0, 1, 2, 4, 5, 6, 8]],
            ['Back office', TagColour::Violet, [1, 3, 8, 9]],
            ['Mobile', TagColour::Emerald, [5]],
        ], 'releases' => [
            // [name, planned in days from now, description, status, indexes of its issues]
            ['2.4.0', -10, 'Display fixes and customer reviews.', ReleaseStatus::Delivered, [5, 6, 13]],
            ['2.5.0', 14, 'Checkout and accounting export.', ReleaseStatus::Open, [1, 3, 4, 9, 11, 15]],
            ['2.6.0', 45, 'Payment in three instalments and supplier search.', ReleaseStatus::Open, [8, 10]],
            ['2.3.1', -40, 'Patch cancelled, taken over by 2.4.0.', ReleaseStatus::Abandoned, []],
        ], 'budgets' => [
            // [name, volume in days, lines: [index of the issue or null, label, status, days]]: the shop counts in days;
            // its first order is used up, the second holds a line in each status and one that names no issue
            ['Open order 2026-01', 10, [
                [9, null, BudgetLineStatus::Approved, 4],
                [10, null, BudgetLineStatus::Approved, 3],
                [13, null, BudgetLineStatus::Approved, 2],
                [11, null, BudgetLineStatus::Approved, 1],
            ]],
            ['Open order 2026-10', 5, [
                [11, 'Animated version of the banner', BudgetLineStatus::Approved, 1],
                [null, 'PHP upgrade of the production server', BudgetLineStatus::Approved, 0.5],
                [12, null, BudgetLineStatus::Pending, 1.5],
                [8, null, BudgetLineStatus::ToEstimate, null],
                [14, null, BudgetLineStatus::Refused, 2],
            ]],
        ]];
        yield ['project' => $this->project($dupont, 'Intranet', 'INT', 'Internal tool of the stores.', developers: ['marc']), 'people' => ['manager' => 'thomas', 'developers' => ['marc'], 'client' => 'claire'], 'issues' => [
            ['bug', 'The schedule does not show public holidays', 'to_do', Criticality::Normal, 'marc'],
            ['bug', 'Cannot log in from the Lyon store', 'in_progress', Criticality::Blocking, 'marc'],
            ['evolution', 'Staff directory', 'to_estimate', Criticality::Low],
            ['evolution', 'Online leave requests', 'done', Criticality::Normal, 'marc'],
            ['task', 'Renew the TLS certificate', 'done', Criticality::High, 'marc'],
        ]];
        yield ['project' => $this->project($vallier, 'Supplier portal', 'PRT', 'Upload and tracking of supplier invoices.', developers: ['lea', 'marc']), 'people' => ['manager' => 'thomas', 'developers' => ['lea', 'marc'], 'client' => 'ines'], 'issues' => [
            ['bug', 'Invoice upload fails above 5 MB', 'to_test', Criticality::High, 'marc'],
            ['bug', 'Wrong date format in the export', 'new', Criticality::Normal],
            ['bug', 'Forgotten password sends no email', 'done', Criticality::Blocking, 'lea'],
            ['evolution', 'Electronic signature of purchase orders', 'planned', Criticality::High, 'lea'],
            ['evolution', 'Payments dashboard', 'in_progress', Criticality::Normal, 'marc'],
            ['evolution', 'Automatic reminders for overdue invoices', 'new', Criticality::Normal],
            ['task', 'Move the storage to S3', 'in_progress', Criticality::Normal, 'marc'],
        ]];
        yield ['project' => $this->project($vallier, 'Mobile app', 'APP', 'App of the field sales team.', developers: ['nadia']), 'people' => ['manager' => 'thomas', 'developers' => ['nadia'], 'client' => 'ines'], 'issues' => [
            ['bug', 'Crash on startup on Android 12', 'to_do', Criticality::Blocking, 'nadia'],
            ['bug', 'Offline sync loses the notes', 'in_progress', Criticality::High, 'nadia'],
            ['evolution', 'Dark mode', 'to_estimate', Criticality::Low],
            ['evolution', 'Barcode scanner', 'to_test', Criticality::Normal, 'nadia'],
        ]];
        yield ['project' => $this->project($natura, 'Back-office', 'BO', 'Catalogue and order management.', developers: ['lea']), 'people' => ['manager' => 'yann', 'developers' => ['lea'], 'client' => 'remi'], 'issues' => [
            ['bug', 'Colour variants are duplicated on import', 'in_progress', Criticality::High, 'lea'],
            ['bug', 'Sorting by order date is reversed', 'to_test', Criticality::Normal, 'lea'],
            ['bug', 'Truncated label on the customer page', 'new', Criticality::Low],
            ['evolution', 'Stock import from a file', 'done', Criticality::Normal, 'lea'],
            ['evolution', 'Price history', 'to_estimate', Criticality::Normal],
            ['task', 'Delete the test accounts', 'new', Criticality::Low, 'lea', true],
        ], 'releases' => [
            ['1.3.0', 20, 'Catalogue import and order sorting.', ReleaseStatus::Open, [0, 1, 5]],
        ]];
        yield ['project' => $this->project($travelia, 'Booking site', 'RES', 'Holiday booking.', developers: ['marc']), 'people' => ['manager' => 'yann', 'developers' => ['marc'], 'client' => 'sophie'], 'archived' => true, 'issues' => [
            ['bug', 'Double booking possible on the last slot', 'done', Criticality::Blocking, 'marc'],
            ['evolution', 'Payment in several currencies', 'done', Criticality::Normal, 'marc'],
            ['evolution', 'Holiday comparison', 'declined', Criticality::Low],
        ]];
    }

    /**
     * @param array<string, string> $clients user key => role on the workspace
     */
    private function workspace(string $name, string $key, string $notes, string $manager, array $clients): Workspace
    {
        $workspace = WorkspaceFactory::createOne(['name' => $name, 'key' => $key, 'notes' => $notes]);
        // The manager of a client answers for it on every project: the referent the automation rules aim at.
        MembershipFactory::createOne(['user' => $this->users[$manager], 'workspace' => $workspace, 'role' => 'project_manager', 'referent' => true]);
        foreach ($clients as $user => $role) {
            MembershipFactory::createOne(['user' => $this->users[$user], 'workspace' => $workspace, 'role' => $role]);
        }

        return $workspace;
    }

    /**
     * @param list<string> $developers user keys granted the developer role on the project
     */
    private function project(Workspace $workspace, string $name, string $key, string $description, array $developers): Project
    {
        $project = ProjectFactory::createOne(['workspace' => $workspace, 'name' => $name, 'key' => $key, 'description' => $description]);
        foreach ($developers as $developer) {
            MembershipFactory::createOne(['user' => $this->users[$developer], 'project' => $project, 'role' => 'developer']);
        }

        return $project;
    }

    /**
     * The automation rules of a project, written once its issues are: they
     * act from then on, the history above stays what people did. New
     * issues and the answers of the client go to the referent project
     * manager; an issue left with the client is reminded after a month,
     * and the rule that would close it two weeks later is there, switched
     * off.
     */
    private function createRules(Project $project, Tracker $bugs): void
    {
        foreach ($this->kit->rules() as [$name, $onBugs, $definition, $active]) {
            $this->commands->dispatch(new CreateRule((int) $project->getId(), $name, $onBugs ? (int) $bugs->getId() : null, $definition, $active));
        }
    }

    /**
     * @return array<string, Tracker> workflow id => tracker
     */
    private function createTrackers(Project $project): array
    {
        return [
            'bug' => TrackerFactory::createOne(['project' => $project, 'name' => $this->workflows->definition('bug')->label, 'workflowId' => 'bug', 'position' => 0, 'description' => 'Something no longer works as before.']),
            'evolution' => TrackerFactory::createOne(['project' => $project, 'name' => $this->workflows->definition('evolution')->label, 'workflowId' => 'evolution', 'position' => 1, 'description' => 'A new feature or an improvement.']),
            'task' => TrackerFactory::createOne(['project' => $project, 'name' => $this->workflows->definition('task')->label, 'workflowId' => 'task', 'position' => 2, 'description' => 'Technical work without a client request.', 'private' => true]),
        ];
    }

    /**
     * The tags of a project, created before its issues, which are then
     * created with theirs: no history to rewrite.
     *
     * @param list<array{0: string, 1: TagColour, 2: list<int>}> $definitions
     *
     * @return array<int, list<int>> index of an issue of the project => the ids of its tags
     */
    private function createTags(Project $project, User $manager, array $definitions): array
    {
        $tagged = [];
        foreach ($definitions as [$name, $colour, $indexes]) {
            $tag = $this->commands->dispatch(new CreateTag((int) $manager->getId(), (int) $project->getId(), $name, $colour));
            \assert($tag instanceof Tag);
            foreach ($indexes as $index) {
                $tagged[$index][] = (int) $tag->getId();
            }
        }

        return $tagged;
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: Criticality, 4?: ?string, 5?: bool} $definition
     * @param array{manager: string, developers: list<string>, client: ?string}             $people
     * @param list<int>                                                                     $tagIds     the tags it is created with
     */
    private function createIssue(Project $project, Tracker $tracker, array $definition, array $people, \DateTimeImmutable $createdAt, \DateTimeImmutable $now, int $index, array $tagIds = []): Issue
    {
        [, $title, $targetPlace, $criticality] = $definition;
        $assignee = $definition[4] ?? null;
        $workflow = $this->workflows->definition($tracker->getWorkflowId());

        // Clients report bugs and evolutions; internal users write internal tasks.
        $author = $tracker->isPrivate() || null === $people['client'] || 0 === $index % 4 ? $this->users[$people['manager']] : $this->users[$people['client']];
        $clock = new MockClock($createdAt);
        Clock::set($clock);

        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $author->getId(),
            projectId: (int) $project->getId(),
            trackerId: (int) $tracker->getId(),
            title: $title,
            description: 'Reported by '.$author->getFullName().'. See the details below.',
            criticality: $criticality,
            private: $definition[5] ?? false,
            fields: self::fieldsFor($workflow, $index),
            tagIds: $tagIds,
        ));
        \assert($issue instanceof Issue);

        if (null !== $assignee) {
            $this->commands->dispatch(new UpdateIssue((int) $this->users[$people['manager']]->getId(), $issue->getKey(), assigneeId: $this->users[$assignee]->getId()));
        }

        // Two issues out of three get a thread.
        if (2 !== $index % 3) {
            $this->comment($issue, $people, $clock, $index);
        }

        foreach ($workflow->trailTo($targetPlace) as $step => $place) {
            $clock->modify(\sprintf('+%d hours +%d minutes', 2 + ($index * 7 + $step * 11) % 38, ($index * 13) % 60));
            if ($clock->now() > $now) {
                $clock = new MockClock($now->modify('-1 hour'));
                Clock::set($clock);
            }
            $this->advance($issue, $place, $people);
        }

        return $issue;
    }

    /**
     * Links between issues, made by the manager of Maison Dupont.
     */
    /**
     * An issue waits for the hosting team, whoever of it takes it.
     */
    private function assignToTeams(\DateTimeImmutable $now): void
    {
        Clock::set(new MockClock($now->modify('-20 minutes')));
        $this->commands->dispatch(new UpdateIssue((int) $this->users['thomas']->getId(), 'MD-SPT-18', assigneeTeamId: $this->teams['Hosting']->getId()));
    }

    private function createLinks(\DateTimeImmutable $now): void
    {
        Clock::set(new MockClock($now->modify('-30 minutes')));
        $thomas = (int) $this->users['thomas']->getId();
        foreach ([
            ['MD-SPT-4', 'blocks', 'MD-SPT-10'],
            ['MD-SPT-1', 'related', 'MD-SPT-16'],
            ['MD-SPT-8', 'duplicates', 'MD-SPT-2'],
            ['MD-INT-2', 'related', 'MD-SPT-1'],
        ] as [$key, $type, $otherKey]) {
            $this->commands->dispatch(new AddLink($thomas, $key, $type, $otherKey));
        }
    }

    /**
     * The shop's repository, linked by Thomas without a webhook (the dev
     * token is empty), and what GitLab would have told about MD-SPT-4 and
     * MD-SPT-3: a push on main, a merge request opened then merged, a
     * commit alone on a branch.
     */
    private function createCode(\DateTimeImmutable $now): void
    {
        $shop = $this->entityManager->getRepository(Project::class)->findOneBy(['key' => 'SPT']) ?? throw new \LogicException('The shop is missing.');
        Clock::set(new MockClock($now->modify('-3 days')));
        $repository = $this->commands->dispatch(new LinkRepository((int) $shop->getId(), Forge::GitLab, 'maison-dupont/shop', (int) $this->users['thomas']->getId()));
        \assert($repository instanceof CodeRepository);
        $id = (int) $repository->getId();
        $base = 'https://gitlab.example/maison-dupont/shop/-/';
        $commit = static fn (string $sha, string $title, string $author): CommitPayload => new CommitPayload($sha, $title, $title, $base.'commit/'.$sha, $author);
        $mergeRequest = static fn (MergeRequestState $state): MergeRequestPayload => new MergeRequestPayload(431, 'fix(export): paginate the CSV export of orders', '', $base.'merge_requests/431', 'fix/MD-SPT-4-export-csv', $state, 'Nadia Benali');

        Clock::set(new MockClock($now->modify('-2 days 14:00')));
        $this->commands->dispatch(new RecordMergeRequest($id, 'MD-SPT-4', $mergeRequest(MergeRequestState::Opened)));
        Clock::set(new MockClock($now->modify('-2 days 16:20')));
        $this->commands->dispatch(new RecordCommits($id, 'MD-SPT-4', 'main', $base.'tree/main', [
            $commit('b6568db1bc1dcd7f8b4d5a946b0b91f9dacd7327', 'fix(export): paginate the CSV export of orders (MD-SPT-4)', 'Nadia Benali'),
            $commit('da1560886d4f094c3e6c9ef40349f7d38b5d27d7', 'test(export): the 8,000 orders case MD-SPT-4', 'Nadia Benali'),
            $commit('1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b', 'chore: changelog MD-SPT-4', 'Léa Fontaine'),
        ]));
        $this->commands->dispatch(new RecordMergeRequest($id, 'MD-SPT-4', $mergeRequest(MergeRequestState::Merged)));
        Clock::set(new MockClock($now->modify('-1 day 10:12')));
        $this->commands->dispatch(new RecordCommits($id, 'MD-SPT-3', 'feat/md-spt-3-filtres', $base.'tree/feat/md-spt-3-filtres', [
            $commit('9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d', 'feat(catalogue): size filters, first draft (MD-SPT-3)', 'Léa Fontaine'),
        ]));
    }

    /**
     * Gives the project days as its unit, then creates its budgets and
     * their lines, the first budget a few weeks ago, the next ones since.
     *
     * @param list<array{0: string, 1: int, 2: list<array{0: ?int, 1: ?string, 2: BudgetLineStatus, 3: float|int|null}>}> $definitions
     * @param list<Issue>                                                                                                 $issues      the issues of the project, in creation order
     */
    private function createBudgets(Project $project, User $manager, array $definitions, array $issues, \DateTimeImmutable $now): void
    {
        if ([] === $definitions) {
            return;
        }
        $actorId = (int) $manager->getId();
        $this->commands->dispatch(new UpdateProject((int) $project->getId(), actorId: $actorId, budgetUnit: BudgetUnit::Days));

        foreach ($definitions as $index => [$name, $days, $lines]) {
            $clock = new MockClock($now->modify(\sprintf('-%d days', 20 * (\count($definitions) - $index))));
            Clock::set($clock);
            $budget = $this->commands->dispatch(new CreateBudget($actorId, (int) $project->getId(), $name, $this->quantities->toMinutes($days, BudgetUnit::Days)));
            \assert($budget instanceof Budget);
            foreach ($lines as [$issue, $label, $status, $quantity]) {
                $clock->modify('+2 days');
                $this->commands->dispatch(new AddBudgetLine(
                    $actorId,
                    (int) $budget->getId(),
                    $status,
                    null === $quantity ? null : $this->quantities->toMinutes($quantity, BudgetUnit::Days),
                    null === $issue ? null : $issues[$issue]->getId(),
                    $label,
                ));
            }
        }
    }

    /**
     * Creates a release planned relative to now and schedules its issues
     * in it, then delivers or abandons it.
     *
     * @param array{0: string, 1: int, 2: string, 3: ReleaseStatus, 4: list<int>} $definition
     * @param list<Issue>                                                         $issues     the issues of the project, in creation order
     */
    private function createRelease(Project $project, User $manager, array $definition, array $issues, \DateTimeImmutable $now): void
    {
        [$name, $days, $description, $status, $members] = $definition;
        $actorId = (int) $manager->getId();
        // A past release was prepared before its date, a future one is being prepared now.
        Clock::set(new MockClock($now->modify($days < 0 ? \sprintf('%+d days', $days - 3) : '-1 hour')));

        $release = $this->commands->dispatch(new CreateRelease($actorId, (int) $project->getId(), $name, $now->modify(\sprintf('%+d days', $days)), $description));
        \assert($release instanceof Release);
        foreach ($members as $index) {
            $this->commands->dispatch(new UpdateIssue($actorId, $issues[$index]->getKey(), releaseId: $release->getId()));
        }
        if (ReleaseStatus::Open !== $status) {
            Clock::set(new MockClock($now->modify(\sprintf('%+d days', $days))));
            $this->commands->dispatch(new UpdateRelease($actorId, (int) $release->getId(), status: $status));
        }
    }

    /**
     * @param array{manager: string, developers: list<string>, client: ?string} $people
     */
    private function comment(Issue $issue, array $people, MockClock $clock, int $index): void
    {
        $clock->modify(\sprintf('+%d hours +%d minutes', 1 + $index % 6, ($index * 17) % 60));
        $developer = $people['developers'][$index % \count($people['developers'])];
        $this->commands->dispatch(new AddComment((int) $this->users[$developer]->getId(), $issue->getKey(), 'I am looking into it, I will get back to you today.', attachments: [$this->upload($this->users[$developer], $issue, 'trace.log')]));

        if (null !== $people['client'] && !$issue->isPrivate()) {
            $clock->modify(\sprintf('+%d hours +%d minutes', 1 + ($index * 5) % 6, ($index * 23) % 60));
            $this->commands->dispatch(new AddComment((int) $this->users[$people['client']]->getId(), $issue->getKey(), 'Thanks, it is blocking our teams.'));
            if ($issue->getAuthor() === $this->users[$people['client']]) {
                $this->upload($issue->getAuthor(), $issue, 'capture.png', toIssue: true);
            }
        }
        // One thread out of three ends with a private note of the manager.
        if (0 === $index % 3) {
            $manager = $this->users[$people['manager']];
            $this->commands->dispatch(new AddComment((int) $manager->getId(), $issue->getKey(), 'To estimate before committing to a date.', private: true, attachments: [$this->upload($manager, $issue, 'estimate.pdf')]));
        }
    }

    /**
     * Sends one of the sample files of `files/`, pending (its uuid, for the
     * comment that takes it) or straight to the issue.
     */
    private function upload(User $actor, Issue $issue, string $name, bool $toIssue = false): string
    {
        $copy = (string) tempnam(sys_get_temp_dir(), 'cairn');
        copy(__DIR__.'/files/'.$name, $copy);
        $file = new UploadedFile($copy, $name, null, null, true);
        $attachment = $this->commands->dispatch($toIssue
            ? new AddAttachment((int) $actor->getId(), $issue->getKey(), $file)
            : new UploadAttachment((int) $actor->getId(), (int) $issue->getProject()->getId(), $file));
        \assert($attachment instanceof Attachment);

        return $attachment->getUuid()->toRfc4122();
    }

    /**
     * Creation dates spread over the history, oldest first, so that issue
     * numbers follow the calendar: evenly spaced, each one shifted by a few
     * hours so that the spacing does not look mechanical.
     *
     * @return list<\DateTimeImmutable>
     */
    public static function creationDates(\DateTimeImmutable $now, int $count): array
    {
        $span = (self::HISTORY_DAYS - 3) * 24 * 60;
        $dates = [];
        for ($index = 0; $index < $count; ++$index) {
            $minutesAgo = 3 * 24 * 60 + intdiv($span * ($count - $index), $count + 1) + ($index * 97) % 600;
            $dates[] = $now->modify("-{$minutesAgo} minutes");
        }

        return $dates;
    }

    /**
     * Takes the issue one place further on its trail
     * (WorkflowDefinition::trailTo()), as it would happen: by the action
     * of the client when the workflow gives them one that leads there,
     * otherwise by someone of the team, who moves freely.
     *
     * @param array{manager: string, developers: list<string>, client: ?string} $people
     */
    private function advance(Issue $issue, string $place, array $people): void
    {
        $client = null === $people['client'] ? null : $this->users[$people['client']];
        if (null !== $client && $this->access->canSee($client, $issue)) {
            $action = array_find($this->access->transitionsFor($client, $issue), static fn (TransitionDefinition $transition): bool => $transition->to === $place);
            if (null !== $action) {
                $this->commands->dispatch(new ApplyTransition(
                    actorId: (int) $client->getId(),
                    issueKey: $issue->getKey(),
                    transitionId: $action->id,
                    comment: $action->requiresComment ? 'Still reproducible on pre-production, see the screenshot.' : null,
                ));

                return;
            }
        }

        $mover = array_find(
            array_map(fn (string $key): User => $this->users[$key], [...$people['developers'], $people['manager'], 'admin']),
            fn (User $user): bool => $this->access->canMoveFreely($user, $issue->getProject()),
        ) ?? throw new \LogicException(\sprintf('Nobody moves the issues of "%s" freely.', $issue->getProject()->getName()));
        $this->commands->dispatch(new MoveIssue((int) $mover->getId(), $issue->getKey(), $place));
    }

    /**
     * Plausible values for the fields of the workflow, required ones included.
     *
     * @return array<string, mixed>
     */
    public static function fieldsFor(WorkflowDefinition $workflow, int $index): array
    {
        $values = [
            'url' => 'https://www.example.test/'.['panier', 'compte', 'catalogue', 'commande'][$index % 4],
            'due_date' => '2026-12-15',
        ];

        return array_intersect_key($values, $workflow->fields);
    }
}
