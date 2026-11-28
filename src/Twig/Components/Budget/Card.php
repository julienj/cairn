<?php

declare(strict_types=1);

namespace App\Twig\Components\Budget;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Message\Command\Budget\UpdateBudgetLine;
use App\Message\CommandBus;
use App\Message\Query\Budget\BudgetStatement;
use App\Message\Query\Budget\ShowBudget;
use App\Message\QueryBus;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
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
 * One budget with all of its lines, the latest first: what it amounts to,
 * then each line with what it is for, its status and its quantity. The
 * budgets page shows one card per open budget, the page of a budget its
 * own. Whoever manages the budgets marks the status of a line from its
 * menu, there and then: the card redraws with the new figures and tells
 * the page (`budget:changed`), so that its totals follow. Writing a line
 * or changing the rest of it opens a dialog of the page, and so does a
 * status that asks for a quantity the line does not have yet.
 */
#[AsLiveComponent('Budget:Card')]
final class Card
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Budget $budget;

    /** On the page of its budget, not among the others: its dialogs lead back there. */
    #[LiveProp]
    public bool $standalone = false;

    /** Read once per render. */
    private ?BudgetStatement $statement = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The card is drawn, and redrawn, only for who may read the budgets of
     * the project, whatever page or request holds it. The command guards
     * the writing.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        if (!$this->access->canReadBudgets($this->viewer->get(), $this->budget->getProject())) {
            throw new AccessDeniedException('Reading the budgets needs budget.view.');
        }
    }

    public function getStatement(): BudgetStatement
    {
        return $this->statement ??= $this->queries->ask(new ShowBudget($this->viewer->get(), $this->budget));
    }

    /**
     * The statuses the menu of a line offers, in their order.
     *
     * @return list<BudgetLineStatus>
     */
    public function getStatuses(): array
    {
        return BudgetLineStatus::cases();
    }

    /**
     * The lines counted by status, for the statuses that have some, in
     * their order: how many, and what they amount to together, which is
     * unknown where no line of the status says a quantity the viewer may
     * read.
     *
     * @return array<string, array{lines: int, quantity: ?int}>
     */
    public function getByStatus(): array
    {
        $statement = $this->getStatement();
        $totals = [];
        foreach (BudgetLineStatus::cases() as $status) {
            $lines = array_filter($statement->lines, static fn (BudgetLine $line): bool => $line->getStatus() === $status);
            if ([] === $lines) {
                continue;
            }
            $quantities = array_filter(array_map($statement->quantityOf(...), $lines), static fn (?int $quantity): bool => null !== $quantity);
            $totals[$status->value] = ['lines' => \count($lines), 'quantity' => [] === $quantities ? null : array_sum($quantities)];
        }

        return $totals;
    }

    /** Whether the viewer writes the lines; nobody does on an archived project. */
    public function canManage(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::BudgetManage, $this->budget->getProject());
    }

    /**
     * Marks the status of a line of this budget, chosen in its menu.
     */
    #[LiveAction]
    public function mark(CommandBus $commands, #[LiveArg] int $line, #[LiveArg] string $status): void
    {
        $chosen = BudgetLineStatus::tryFrom($status);
        $ofThisBudget = array_any($this->getStatement()->lines, static fn (BudgetLine $shown): bool => $shown->getId() === $line);
        if (null === $chosen || !$ofThisBudget) {
            $this->error = $this->translator->trans('issue.action.refused');

            return;
        }
        if (!$this->tryCommand($commands, new UpdateBudgetLine((int) $this->viewer->get()->getId(), $line, $chosen))) {
            return;
        }

        $this->statement = null;
        $this->emit('budget:changed');
    }
}
