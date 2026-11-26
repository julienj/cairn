<?php

declare(strict_types=1);

namespace App\Twig\Components\Team;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\RemoveTeamMember;
use App\Message\CommandBus;
use App\Repository\TeamMemberRepository;
use App\Repository\UserRepository;
use App\Twig\Components\DispatchesCommands;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
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
 * The members of a team, for the administration: someone is picked in a
 * searchable list and added, a member is taken out after a confirmation
 * in place. The rules are AddTeamMember's; a refusal is shown in place.
 * The component lists the accounts of the instance, so it checks who
 * draws it each time (`guard()`), not only the page that holds it.
 */
#[AsLiveComponent('Team:Members')]
final class Members
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Team $team;

    /** Who joins the team. */
    #[LiveProp(writable: true)]
    public ?int $newUserId = null;

    /** The member, a user id, whose removal waits for a confirmation. */
    #[LiveProp(writable: true)]
    public ?int $removing = null;

    /** @var list<TeamMember>|null read once per render */
    private ?array $loadedMembers = null;

    public function __construct(
        private readonly TeamMemberRepository $members,
        private readonly UserRepository $users,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {
    }

    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('The teams are managed by the administration.');
        }
    }

    /**
     * @return list<TeamMember>
     */
    public function getMembers(): array
    {
        return $this->loadedMembers ??= $this->members->findOfTeam($this->team);
    }

    /**
     * The active accounts that may join: not part of the team yet, and
     * internal when the team is.
     *
     * @return list<User>
     */
    public function getCandidates(): array
    {
        $members = array_map(static fn (TeamMember $member): ?int => $member->getUser()->getId(), $this->getMembers());

        return array_values(array_filter(
            $this->users->findActive(),
            fn (User $user): bool => !\in_array($user->getId(), $members, true) && ($user->isInternal() || !$this->team->isInternal()),
        ));
    }

    public function getNewUser(): ?User
    {
        return null !== $this->newUserId ? $this->users->find($this->newUserId) : null;
    }

    #[LiveAction]
    public function chooseUser(#[LiveArg] int $user): void
    {
        $this->newUserId = $user;
    }

    #[LiveAction]
    public function add(CommandBus $commands): void
    {
        if (null === $this->newUserId) {
            $this->error = $this->translator->trans('admin.team.members.incomplete');

            return;
        }
        if ($this->tryCommand($commands, new AddTeamMember((int) $this->team->getId(), $this->newUserId))) {
            $this->newUserId = null;
            $this->done('admin.team.members.added');
        }
    }

    #[LiveAction]
    public function confirmRemove(#[LiveArg] int $user): void
    {
        $this->removing = $user;
    }

    #[LiveAction]
    public function cancelRemove(): void
    {
        $this->removing = null;
    }

    #[LiveAction]
    public function remove(CommandBus $commands): void
    {
        if (null !== $this->removing && $this->tryCommand($commands, new RemoveTeamMember((int) $this->team->getId(), $this->removing))) {
            $this->done('admin.team.members.removed');
        }
        $this->removing = null;
    }

    private function done(string $message): void
    {
        $this->loadedMembers = null;
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans($message)]);
    }
}
