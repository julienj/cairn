<?php

declare(strict_types=1);

namespace App\Twig\Components\Membership;

use App\Config\ConfigRegistry;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Membership\GrantMembership;
use App\Message\Command\Membership\RevokeMembership;
use App\Message\Command\Membership\SetReferent;
use App\Message\Command\User\CreateUser;
use App\Message\Command\User\InviteUser;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjects;
use App\Message\Query\User\SearchUsers;
use App\Message\Query\Workspace\ListWorkspaces;
use App\Message\QueryBus;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Permission;
use App\Security\Referents;
use App\Security\RoleReferent;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Twig\Components\ManagesRoleRows;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The memberships of a workspace (who holds a role on it), of a user
 * (where they hold one) or of a project (who holds a role on it directly),
 * exactly one of the three: grant a role, change it, take it back after a
 * confirmation. The rules are GrantMembership's; a refusal is shown in
 * place. The first two serve the administration, which acts without an
 * actor and picks the person, or the workspace or project, in a
 * searchable list. On a project, the viewer acts and the roles inherited
 * from the workspace are shown read-only, a direct role only widening
 * them; someone is added in a dialog the page opens (`adding`, from
 * `?add=1` or its button), from one field: what is typed searches the
 * accounts of the instance (`suggestions`: a name, a company, an
 * address), one of them is chosen and gets a role; an address no account
 * uses gets an external account, the role and an invitation. Every
 * account shows there, those that cannot be chosen with the reason, a
 * deactivated one included, so that nobody creates it again. The
 * component lists accounts on demand, so it checks who draws it each
 * time (`guard()`), not only the page that holds it.
 * A member who has not chosen their password yet can be invited again.
 * The referents are managed in one place, a card over the list of a
 * project or a workspace (`referentRows`, SetReferent): who answers for
 * each role, chosen in a menu; on a project, the referent it designates
 * among its direct members replaces the one of the workspace. The rows
 * of the lists say nothing of it.
 */
#[AsLiveComponent('Membership:Grants')]
final class Grants
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use ManagesRoleRows;

    /** What an account may be given on the project: a role (`existing`), or the reason why not. */
    public const string EXISTING = 'existing';
    public const string MEMBER = 'member';
    public const string CEILING = 'ceiling';
    public const string DEACTIVATED = 'deactivated';

    #[LiveProp]
    public ?Workspace $workspace = null;

    #[LiveProp]
    public ?User $user = null;

    #[LiveProp]
    public ?Project $project = null;

    /** On a workspace: who gets the new role. */
    #[LiveProp(writable: true)]
    public ?int $newUserId = null;

    /** For a user: where the new role applies, `workspace-3` or `project-5`. */
    #[LiveProp(writable: true)]
    public string $newTarget = '';

    #[LiveProp(writable: true)]
    public string $newRole = '';

    /** On a project: whether the dialog adding someone is open; the page opens it when its address says `?add=1`. */
    #[LiveProp(writable: true)]
    public bool $adding = false;

    /** How many accounts the search offers at once. */
    public const int SUGGESTIONS = 6;

    /** On a project: what is typed to find who gets the new role, a name or an address. */
    #[LiveProp(writable: true, onUpdated: 'onSearchChanged')]
    public string $search = '';

    /** On a project: the account chosen among those found. */
    #[LiveProp]
    public ?int $chosenId = null;

    /** On a project: the address typed, which no account uses, gets an account. */
    #[LiveProp]
    public bool $inviting = false;

    /** On a project, for an unknown address: the account to create. */
    #[LiveProp(writable: true)]
    public string $firstName = '';

    #[LiveProp(writable: true)]
    public string $lastName = '';

    #[LiveProp(writable: true)]
    public string $company = '';

    /** @var list<Membership>|null read once per render, `align()` forgets it */
    private ?array $loadedMemberships = null;

    /** @var array<int, Membership>|null read once per render, `align()` forgets it */
    private ?array $loadedInherited = null;

    /** @var array{accounts: list<array{user: User, state: string}>, more: int}|null what `search` finds, read once per render */
    private ?array $loadedSuggestions = null;

    /** @var list<array{workspace: Workspace, projects: list<Project>}>|null read once per render */
    private ?array $loadedTargets = null;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly QueryBus $queries,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
        private readonly Viewer $viewer,
        private readonly ValidatorInterface $validator,
        private readonly Referents $referents,
        private readonly Security $security,
    ) {
    }

    public function mount(?Workspace $workspace = null, ?User $user = null, ?Project $project = null, bool $adding = false): void
    {
        $this->adding = $adding && null !== $project;
        if (1 !== \count(array_filter([$workspace, $user, $project]))) {
            throw new \LogicException('Membership:Grants lists the memberships of a workspace, of a user or of a project.');
        }
        $this->workspace = $workspace;
        $this->user = $user;
        $this->project = $project;
        $this->align();
    }

    /**
     * Who draws the component, and calls its actions, each time: on a
     * project whoever manages its members, the administration otherwise.
     * It lists the accounts of the instance, which the address of the
     * page alone must not give.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $granted = null !== $this->project
            ? $this->security->isGranted(Permission::ProjectManageMembers->value, $this->project)
            : $this->security->isGranted('ROLE_ADMIN');
        if (!$granted) {
            throw new AccessDeniedException('Managing these accesses needs project.manage_members on the project, or the administration.');
        }
    }

    public function onSearchChanged(): void
    {
        $this->loadedSuggestions = null;
        $this->chosenId = null;
        $this->inviting = false;
        $this->newRole = '';
        $this->firstName = '';
        $this->lastName = '';
        $this->company = '';
        $this->error = null;
    }

    /**
     * The first and last name an address of the shape `first.last@…` gives,
     * for an account to create: the person adding only checks them.
     *
     * @return array{string, string}
     */
    private static function guessName(string $email): array
    {
        $local = strstr(trim($email), '@', true);
        $parts = false === $local ? [] : explode('.', $local);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1] || 1 === preg_match('/\d/', $local)) {
            return ['', ''];
        }
        $word = static fn (string $part): string => implode('-', array_map(static fn (string $piece): string => mb_convert_case($piece, \MB_CASE_TITLE), explode('-', $part)));

        return [$word($parts[0]), $word($parts[1])];
    }

    /**
     * @return list<Membership>
     */
    public function getMemberships(): array
    {
        return $this->loadedMemberships ??= $this->loadMemberships();
    }

    /**
     * On a project: the roles inherited from its workspace, by user id.
     *
     * @return array<int, Membership>
     */
    public function getInherited(): array
    {
        return $this->loadedInherited ??= $this->loadInherited();
    }

    /**
     * The referents, managed here and nowhere else: one row per role
     * someone holds on the project or the workspace (or is marked for),
     * in the order of the configuration, with who answers for it, why,
     * and the members who may be designated. On a project the referent
     * it designates among its direct members replaces the one of the
     * workspace, which `fallback` names; for a user, no row.
     *
     * @return list<array{role: string, label: string, user: User|null, origin: string|null, note: string, name: string, warning: bool, candidates: list<Membership>, own: Membership|null, fallback: User|null}> `origin`: `from_project` or `from_workspace` on a project; `note`: a key of `admin.access.referents`, `name` its person; `own`: the membership marked here
     */
    public function getReferentRows(): array
    {
        if (null === $this->project && null === $this->workspace) {
            return [];
        }
        $held = [];
        foreach ($this->getMemberships() as $membership) {
            $held[$membership->getRole()][] = $membership;
        }
        $inheritedRoles = array_map(static fn (Membership $membership): string => $membership->getRole(), $this->getInherited());
        $resolved = null !== $this->project ? $this->referents->resolve($this->project) : [];

        $rows = [];
        foreach ($this->config->definition()->roles as $role) {
            $members = $held[$role->id] ?? [];
            if ([] === $members && !\in_array($role->id, $inheritedRoles, true) && !isset($resolved[$role->id])) {
                continue;
            }
            // a direct role the workspace has since outgrown is not the one its holder has on the project: they could not answer for it
            $candidates = array_values(array_filter($members, fn (Membership $membership): bool => $membership->getUser()->isActive() && !$this->outgrown($membership)));
            $rows[] = ['role' => $role->id, 'label' => $role->label, 'candidates' => $candidates]
                + (null !== $this->project ? self::projectReferent($resolved[$role->id] ?? null, [] !== $members, [] !== $candidates) : self::workspaceReferent($members));
        }

        return $rows;
    }

    /**
     * Whether the role of a direct membership is no longer wider than the
     * one its holder inherits from the workspace, granted since.
     */
    private function outgrown(Membership $membership): bool
    {
        $inherited = ($this->getInherited()[(int) $membership->getUser()->getId()] ?? null)?->getRole();
        $definition = $this->config->definition();

        return null !== $inherited && $definition->roleRank($membership->getRole()) >= $definition->roleRank($inherited);
    }

    /**
     * @return array{user: User|null, origin: string|null, note: string, name: string, warning: bool, own: Membership|null, fallback: User|null}
     */
    private static function projectReferent(?RoleReferent $referent, bool $held, bool $replaceable): array
    {
        $user = $referent?->user();
        $own = $referent?->own;
        [$note, $named] = match (true) {
            null === $referent => [$held ? 'none' : 'none_workspace_only', null],
            null !== $referent->replaced() => ['replaces', $referent->replaced()->getUser()],
            null !== $own && !$referent->ownHolds => [null !== $user ? 'own_idle_fallback' : 'own_idle', $own->getUser()],
            null !== $user => [$replaceable ? 'inherited' : 'inherited_only', null],
            default => ['inherited_idle', $referent->inherited?->getUser()],
        };

        return [
            'user' => $user,
            'origin' => match (true) {
                null === $user => null,
                $referent->isInherited() => 'from_workspace',
                default => 'from_project',
            },
            'note' => $note,
            'name' => (string) $named?->getFullName(),
            'warning' => \in_array($note, ['own_idle', 'own_idle_fallback', 'inherited_idle'], true),
            'own' => $own,
            'fallback' => $referent?->inheritedHolds ? $referent->inherited?->getUser() : null,
        ];
    }

    /**
     * @param list<Membership> $members those who hold the role on the workspace
     *
     * @return array{user: User|null, origin: string|null, note: string, name: string, warning: bool, own: Membership|null, fallback: User|null}
     */
    private static function workspaceReferent(array $members): array
    {
        $own = array_find($members, static fn (Membership $membership): bool => $membership->isReferent());
        $answers = $own?->getUser()->isActive() ?? false;

        return [
            'user' => $answers ? $own?->getUser() : null,
            'origin' => null,
            'note' => match (true) {
                null === $own => 'none',
                $answers => 'workspace',
                default => 'own_idle',
            },
            'name' => (string) $own?->getUser()->getFullName(),
            'warning' => null !== $own && !$answers,
            'own' => $own,
            'fallback' => null,
        ];
    }

    /**
     * The roles of the configuration that may go to the user: an external
     * user gets no internal-only role, and on a project only the roles
     * wider than the one they inherit from the workspace. Without a user
     * yet, all of them, or those of an external user when `external`.
     *
     * @return array<string, string> role id => label
     */
    public function rolesFor(?User $user, bool $external = false): array
    {
        $definition = $this->config->definition();
        $inherited = null !== $user ? ($this->getInherited()[(int) $user->getId()] ?? null)?->getRole() : null;
        $ceiling = null !== $inherited ? $definition->roleRank($inherited) : null;
        $external = $external || (null !== $user && !$user->isInternal());

        $roles = [];
        foreach ($definition->roles as $role) {
            if ($external && $role->internalOnly) {
                continue;
            }
            if (null !== $ceiling && $definition->roleRank($role->id) >= $ceiling) {
                continue;
            }
            $roles[$role->id] = $role->label;
        }

        return $roles;
    }

    /**
     * On a workspace: the active users who hold no role on it yet and may
     * be given one.
     *
     * @return list<User>
     */
    public function getCandidates(): array
    {
        $members = array_map(static fn (Membership $membership): ?int => $membership->getUser()->getId(), $this->getMemberships());

        return array_values(array_filter(
            $this->users->findActive(),
            fn (User $user): bool => !\in_array($user->getId(), $members, true) && [] !== $this->rolesFor($user),
        ));
    }

    public function getNewUser(): ?User
    {
        return null !== $this->newUserId ? $this->users->find($this->newUserId) : null;
    }

    /**
     * For a user: the workspaces, each with its projects.
     *
     * @return list<array{workspace: Workspace, projects: list<Project>}>
     */
    public function getTargets(): array
    {
        return $this->loadedTargets ??= $this->loadTargets();
    }

    /**
     * @return list<array{workspace: Workspace, projects: list<Project>}>
     */
    private function loadTargets(): array
    {
        $projects = [];
        foreach ($this->queries->ask(new ListProjects()) as $project) {
            $projects[(int) $project->getWorkspace()->getId()][] = $project;
        }
        $targets = [];
        foreach ($this->queries->ask(new ListWorkspaces()) as $workspace) {
            $targets[] = ['workspace' => $workspace, 'projects' => $projects[(int) $workspace->getId()] ?? []];
        }

        return $targets;
    }

    /**
     * What the chosen target reads as, for the picker's button.
     */
    public function getTargetLabel(): ?string
    {
        [$kind, $id] = explode('-', $this->newTarget.'-', 3) + [1 => ''];
        foreach ($this->getTargets() as ['workspace' => $workspace, 'projects' => $projects]) {
            if ('workspace' === $kind && $workspace->getId() === (int) $id) {
                return $this->translator->trans('admin.access.whole_workspace', ['%name%' => $workspace->getName()]);
            }
            foreach ($projects as $project) {
                if ('project' === $kind && $project->getId() === (int) $id) {
                    return $project->getIssuePrefix().' · '.$project->getName();
                }
            }
        }

        return null;
    }

    /**
     * The companies already known, suggested when creating an account.
     *
     * @return list<string>
     */
    public function getCompanies(): array
    {
        return $this->users->findCompanies();
    }

    /**
     * On a project: the accounts matching what is typed (names, company,
     * address), the one whose address it is first, each with what it may
     * be given (`stateOf()`), and how many more match. Nothing until two
     * characters are typed, nor once someone is chosen.
     *
     * @return array{accounts: list<array{user: User, state: string}>, more: int}
     */
    public function getSuggestions(): array
    {
        return $this->loadedSuggestions ??= $this->suggestions();
    }

    /**
     * @return array{accounts: list<array{user: User, state: string}>, more: int}
     */
    private function suggestions(): array
    {
        $text = trim($this->search);
        if (null === $this->project || !$this->adding || null !== $this->chosenId || $this->inviting || mb_strlen($text) < 2) {
            return ['accounts' => [], 'more' => 0];
        }
        $page = $this->queries->ask(new SearchUsers($text));
        $found = [];
        foreach ([$this->users->loadUserByIdentifier($text), ...$page->items] as $user) {
            if (null !== $user) {
                $found[(int) $user->getId()] ??= $user;
            }
        }
        $shown = \array_slice(array_values($found), 0, self::SUGGESTIONS);

        return [
            'accounts' => array_map(fn (User $user): array => ['user' => $user, 'state' => $this->stateOf($user)], $shown),
            'more' => max(0, $page->total - \count($shown)),
        ];
    }

    /**
     * On a project: what an account may be given there, one of the
     * constants. Only `existing` is chosen.
     */
    public function stateOf(User $user): string
    {
        if (!$user->isActive()) {
            return self::DEACTIVATED;
        }
        foreach ($this->getMemberships() as $membership) {
            if ($membership->getUser()->getId() === $user->getId()) {
                return self::MEMBER;
            }
        }

        return [] === $this->rolesFor($user) ? self::CEILING : self::EXISTING;
    }

    /**
     * On a project: the account chosen, as long as it may still be given a role.
     */
    public function getChosen(): ?User
    {
        $chosen = null !== $this->chosenId && null !== $this->project ? $this->users->find($this->chosenId) : null;

        return null !== $chosen && self::EXISTING === $this->stateOf($chosen) ? $chosen : null;
    }

    /**
     * On a project: whether what is typed is an address that may get an
     * account, one no account uses yet.
     */
    public function canInvite(): bool
    {
        $email = trim($this->search);

        // what `CreateUser` would refuse is not offered: one rule says what an address is
        return null !== $this->project
            && '' !== $email
            && 0 === \count($this->validator->validatePropertyValue(CreateUser::class, 'email', $email))
            && null === $this->users->loadUserByIdentifier($email);
    }

    #[LiveAction]
    public function chooseTarget(#[LiveArg] string $target): void
    {
        $this->newTarget = $target;
    }

    #[LiveAction]
    public function chooseUser(#[LiveArg] int $user): void
    {
        $this->newUserId = $user;
    }

    /**
     * On a project: chooses one of the accounts found, which then gets a role.
     */
    #[LiveAction]
    public function choose(#[LiveArg] int $user): void
    {
        $this->error = null;
        $account = null !== $this->project ? $this->users->find($user) : null;
        if (null === $account || self::EXISTING !== $this->stateOf($account)) {
            return;
        }
        $this->chosenId = $user;
        $this->inviting = false;
        $this->newRole = '';
        $this->loadedSuggestions = null;
    }

    /**
     * On a project: takes the address typed as that of a new account,
     * whose name it may give.
     */
    #[LiveAction]
    public function startInvite(): void
    {
        $this->error = null;
        if (!$this->canInvite()) {
            return;
        }
        $this->inviting = true;
        $this->chosenId = null;
        $this->newRole = '';
        [$this->firstName, $this->lastName] = self::guessName($this->search);
        $this->company = '';
        $this->loadedSuggestions = null;
    }

    /**
     * On a project: back to the search, what was typed kept.
     */
    #[LiveAction]
    public function unchoose(): void
    {
        $this->error = null;
        $this->chosenId = null;
        $this->inviting = false;
        $this->newRole = '';
        $this->loadedSuggestions = null;
    }

    #[LiveAction]
    public function grant(CommandBus $commands): void
    {
        $this->error = null;
        if (null !== $this->workspace) {
            $command = null !== $this->newUserId ? new GrantMembership($this->newUserId, $this->workspace->getId(), null, $this->newRole) : null;
        } elseif (null !== $this->project) {
            $chosen = $this->getChosen();
            $command = null !== $chosen ? new GrantMembership((int) $chosen->getId(), null, $this->project->getId(), $this->newRole, $this->actorId()) : null;
        } else {
            [$kind, $id] = explode('-', $this->newTarget.'-', 3) + [1 => ''];
            $command = match ($kind) {
                'workspace' => new GrantMembership((int) $this->user?->getId(), (int) $id, null, $this->newRole),
                'project' => new GrantMembership((int) $this->user?->getId(), null, (int) $id, $this->newRole),
                default => null,
            };
        }
        if (null === $command || '' === $this->newRole) {
            $this->error = $this->translator->trans('admin.access.incomplete');

            return;
        }
        if (!$this->tryCommand($commands, $command)) {
            return;
        }

        $this->reset();
        $this->adding = false;
        $this->done('admin.access.granted');
    }

    /**
     * On a project, for an address no account uses: the external account, its
     * role, then its invitation, each its own command. A refusal stops
     * there and is shown; what went through stays.
     */
    #[LiveAction]
    public function invite(CommandBus $commands): void
    {
        $this->error = null;
        if (null === $this->project || !$this->inviting || !$this->canInvite() || '' === $this->newRole) {
            $this->error = $this->translator->trans('admin.access.incomplete');

            return;
        }

        $email = trim($this->search);
        $create = new CreateUser($email, $this->firstName, $this->lastName, company: $this->company, actorId: $this->actorId(), projectId: $this->project->getId());
        if (!$this->tryCommand($commands, $create, $this->translator->trans('project.access.invalid_account'))) {
            return;
        }
        // the account exists from here on: refused further, it is found by the search like any other
        $this->inviting = false;
        $userId = (int) $this->users->loadUserByIdentifier($email)?->getId();

        if (!$this->tryCommand($commands, new GrantMembership($userId, null, $this->project->getId(), $this->newRole, $this->actorId()))) {
            return;
        }
        if (!$this->tryCommand($commands, new InviteUser($userId, $this->actorId(), $this->project->getId()))) {
            return;
        }

        $this->reset();
        $this->adding = false;
        $this->done('project.access.invited');
    }

    /**
     * On a project: opens the dialog adding someone.
     */
    #[LiveAction]
    public function open(): void
    {
        // what a row was refused has nothing to say in the dialog
        $this->error = null;
        $this->adding = true;
    }

    /**
     * On a project: closes the dialog adding someone, forgetting what it held.
     */
    #[LiveAction]
    public function close(): void
    {
        $this->reset();
        $this->error = null;
        $this->adding = false;
    }

    /**
     * Sends a member their invitation again.
     */
    #[LiveAction]
    public function resend(CommandBus $commands, #[LiveArg] int $user): void
    {
        $this->error = null;
        if ($this->tryCommand($commands, new InviteUser($user, $this->actorId(), $this->project?->getId()))) {
            $this->done('project.access.resent');
        }
    }

    #[LiveAction]
    public function changeRole(CommandBus $commands, #[LiveArg] int $membership): void
    {
        $this->error = null;
        $current = $this->memberships->find($membership);
        if (null === $current) {
            return;
        }
        $command = new GrantMembership(
            (int) $current->getUser()->getId(),
            $current->getWorkspace()?->getId(),
            $current->getProject()?->getId(),
            $this->roles[$membership] ?? '',
            $this->actorId(),
        );
        if (!$this->tryCommand($commands, $command)) {
            $this->align();

            return;
        }

        $this->done('admin.access.changed');
    }

    /**
     * Makes the member of a row the referent of their role, or no longer.
     */
    #[LiveAction]
    public function setReferent(CommandBus $commands, #[LiveArg] int $membership, #[LiveArg] int $referent): void
    {
        $this->error = null;
        if ($this->tryCommand($commands, new SetReferent($membership, 1 === $referent, $this->actorId()))) {
            $this->done(1 === $referent ? 'admin.access.referent_designated' : 'admin.access.referent_withdrawn');
        }
    }

    #[LiveAction]
    public function revoke(CommandBus $commands): void
    {
        $this->error = null;
        if (null !== $this->revoking && $this->tryCommand($commands, new RevokeMembership($this->revoking, $this->actorId()))) {
            $this->done('admin.access.revoked');
        }
        $this->revoking = null;
    }

    /**
     * On a project, the viewer acts and their rights are checked; the
     * administration acts without an actor.
     */
    private function actorId(): ?int
    {
        return null !== $this->project ? $this->viewer->get()->getId() : null;
    }

    private function reset(): void
    {
        $this->newUserId = null;
        $this->newTarget = '';
        $this->newRole = '';
        $this->search = '';
        $this->chosenId = null;
        $this->inviting = false;
        $this->firstName = '';
        $this->lastName = '';
        $this->company = '';
        $this->loadedSuggestions = null;
    }

    /**
     * Aligns the role of each row with the database, read again.
     */
    private function align(): void
    {
        $this->loadedMemberships = null;
        $this->loadedInherited = null;
        $this->roles = [];
        foreach ($this->getMemberships() as $membership) {
            $this->roles[(int) $membership->getId()] = $membership->getRole();
        }
    }

    /**
     * @return list<Membership>
     */
    private function loadMemberships(): array
    {
        if (null !== $this->workspace) {
            return $this->memberships->findOnWorkspace($this->workspace);
        }
        if (null !== $this->project) {
            return $this->memberships->findOnProject($this->project);
        }

        return $this->memberships->findOfUser($this->user ?? throw new \LogicException('Membership:Grants needs a workspace, a user or a project.'));
    }

    /**
     * @return array<int, Membership>
     */
    private function loadInherited(): array
    {
        if (null === $this->project) {
            return [];
        }
        $inherited = [];
        foreach ($this->memberships->findOnWorkspace($this->project->getWorkspace()) as $membership) {
            $inherited[(int) $membership->getUser()->getId()] = $membership;
        }

        return $inherited;
    }
}
