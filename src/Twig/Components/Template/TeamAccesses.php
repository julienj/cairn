<?php

declare(strict_types=1);

namespace App\Twig\Components\Template;

use App\Config\ConfigRegistry;
use App\Entity\ProjectTemplate;
use App\Entity\Team;
use App\Entity\TemplateTeamAccess;
use App\Message\Command\Template\GrantTemplateTeamAccess;
use App\Message\Command\Template\RevokeTemplateTeamAccess;
use App\Message\CommandBus;
use App\Repository\TeamRepository;
use App\Repository\TemplateTeamAccessRepository;
use App\Twig\Components\DispatchesCommands;
use App\Twig\Components\ManagesRoleRows;
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
 * The roles a project template gives to teams on the projects made from
 * it: a team is picked and given a role among those it may hold (none of
 * the internal-only ones for an external team), a role is changed, an
 * access taken back after a confirmation in place. The rules are
 * GrantTemplateTeamAccess's; a refusal is shown in place. For the
 * administration, which the component checks each time it is drawn.
 */
#[AsLiveComponent('Template:TeamAccesses')]
final class TeamAccesses
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use ManagesRoleRows;

    #[LiveProp]
    public ProjectTemplate $template;

    /** The team that gets the new role. */
    #[LiveProp(writable: true)]
    public ?int $newTeamId = null;

    #[LiveProp(writable: true)]
    public string $newRole = '';

    /** @var list<TemplateTeamAccess>|null read once per render, `align()` forgets it */
    private ?array $loadedAccesses = null;

    public function __construct(
        private readonly TemplateTeamAccessRepository $accesses,
        private readonly TeamRepository $teams,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {
    }

    public function mount(ProjectTemplate $template): void
    {
        $this->template = $template;
        $this->align();
    }

    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('The project templates are managed by the administration.');
        }
    }

    /**
     * @return list<TemplateTeamAccess>
     */
    public function getAccesses(): array
    {
        return $this->loadedAccesses ??= $this->accesses->findByTemplate($this->template);
    }

    /**
     * The teams the template names no role for yet.
     *
     * @return list<Team>
     */
    public function getCandidates(): array
    {
        $named = array_map(static fn (TemplateTeamAccess $access): ?int => $access->getTeam()->getId(), $this->getAccesses());

        return array_values(array_filter($this->teams->findAllByName(), static fn (Team $team): bool => !\in_array($team->getId(), $named, true)));
    }

    public function getNewTeam(): ?Team
    {
        return null !== $this->newTeamId ? $this->teams->find($this->newTeamId) : null;
    }

    /**
     * The roles a team may be given: none of the internal-only ones for
     * an external team. Every role until a team is chosen.
     *
     * @return array<string, string> role id => label
     */
    public function rolesFor(?Team $team): array
    {
        $roles = [];
        foreach ($this->config->definition()->roles as $role) {
            if (!$role->internalOnly || false !== $team?->isInternal()) {
                $roles[$role->id] = $role->label;
            }
        }

        return $roles;
    }

    /**
     * Chooses the team; a role already chosen that it may not hold is forgotten.
     */
    #[LiveAction]
    public function chooseTeam(#[LiveArg] int $team): void
    {
        $this->newTeamId = $team;
        if (!isset($this->rolesFor($this->getNewTeam())[$this->newRole])) {
            $this->newRole = '';
        }
    }

    #[LiveAction]
    public function grant(CommandBus $commands): void
    {
        $this->error = null;
        if (null === $this->newTeamId || '' === $this->newRole) {
            $this->error = $this->translator->trans('admin.template.access.incomplete');

            return;
        }
        if (!$this->tryCommand($commands, new GrantTemplateTeamAccess((int) $this->template->getId(), $this->newTeamId, $this->newRole))) {
            return;
        }

        $this->newTeamId = null;
        $this->newRole = '';
        $this->done('admin.access.granted');
    }

    #[LiveAction]
    public function changeRole(CommandBus $commands, #[LiveArg] int $access): void
    {
        $this->error = null;
        $current = $this->accesses->find($access);
        if (null === $current || $current->getTemplate() !== $this->template) {
            return;
        }
        if (!$this->tryCommand($commands, new GrantTemplateTeamAccess((int) $this->template->getId(), (int) $current->getTeam()->getId(), $this->roles[$access] ?? ''))) {
            $this->align();

            return;
        }

        $this->done('admin.access.changed');
    }

    #[LiveAction]
    public function revoke(CommandBus $commands): void
    {
        $this->error = null;
        $current = null !== $this->revoking ? $this->accesses->find($this->revoking) : null;
        // An access of another template is not this page's to take back.
        if (null !== $current && $current->getTemplate() === $this->template && $this->tryCommand($commands, new RevokeTemplateTeamAccess((int) $current->getId()))) {
            $this->done('admin.access.revoked');
        }
        $this->revoking = null;
    }

    /**
     * Aligns the role of each row with the database, read again.
     */
    private function align(): void
    {
        $this->loadedAccesses = null;
        $this->roles = [];
        foreach ($this->getAccesses() as $access) {
            $this->roles[(int) $access->getId()] = $access->getRole();
        }
    }
}
