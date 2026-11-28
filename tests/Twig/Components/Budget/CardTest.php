<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Budget;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Repository\BudgetLineRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The card of an order of ten days of the shop: a day and a half approved
 * for MD-SPT-8, two days waiting for MD-SPT-7, half a day approved for
 * MD-SPT-6, an internal task a client does not see, and an audit still to
 * estimate. Thomas manages the budgets, Claire, the client, reads them,
 * Marc has no role on the shop.
 */
final class CardTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Budget $budget;
    private BudgetLine $pending;
    private BudgetLine $toEstimate;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->budget = $this->scene->budget('Order 2026-01', 10);
        $this->scene->line($this->budget, BudgetLineStatus::Approved, 1.5, 'MD-SPT-8');
        $this->pending = $this->scene->line($this->budget, BudgetLineStatus::Pending, 2, 'MD-SPT-7', 'Second instalment plan');
        $this->scene->line($this->budget, BudgetLineStatus::Approved, 0.5, 'MD-SPT-6');
        $this->toEstimate = $this->scene->line($this->budget, BudgetLineStatus::ToEstimate, null, label: 'Audit of the performance');
    }

    public function testTheManagerMarksTheStatusOfALineFromItsMenu(): void
    {
        $card = $this->card('thomas.girard@cairn.test');
        $render = $card->render();
        self::assertStringContainsString('Consumed 2 d of 10 d', $render->crawler()->text(), 'what was consumed, out of the volume');
        self::assertStringContainsString('20 %', $render->crawler()->text());
        self::assertStringContainsString('Consumed 2 d To approve 2 d Left 8 d', $render->crawler()->filter('dl')->text(), 'the two colours of the bar, each beside its figure, then what is left');
        self::assertStringContainsString('MD-SPT-6', (string) $render, 'the manager sees the internal task');
        self::assertCount(10, $render->crawler()->filter('[data-live-action-param="mark"]'), 'each line offers the statuses it does not have, less the two that ask the audit for a quantity');

        $card->call('mark', ['line' => $this->pending->getId(), 'status' => 'approved']);
        $render = $card->render();

        self::assertSame(BudgetLineStatus::Approved, $this->statusOf($this->pending));
        self::assertStringContainsString('Consumed 4 d of 10 d', $render->crawler()->text(), 'the figures of the card follow');
        self::assertStringContainsString('Consumed 4 d Left 6 d', $render->crawler()->filter('dl')->text());
        self::assertStringNotContainsString('To approve', $render->crawler()->filter('dl')->text(), 'nothing waits any more');
        self::assertNotNull($card->getEmittedEvent($render, 'budget:changed'), 'the totals of the page are told');
    }

    public function testALineWithoutAQuantityIsRefusedInPlaceAndOpensItsDialogForTheRest(): void
    {
        $card = $this->card('thomas.girard@cairn.test');
        $dialog = \sprintf('/MD/SPT/budgets/%d/lines/%d', $this->budget->getId(), $this->toEstimate->getId());
        $asking = static fn (TestLiveComponent $card): array => $card->render()->crawler()->filter('a[data-turbo-frame="modal"][href*="status="]')->each(static fn ($link): string => (string) $link->attr('href'));

        self::assertSame([$dialog.'?status=pending', $dialog.'?status=approved'], $asking($card), 'what asks for a quantity opens the dialog of the line, the status chosen');

        $card->call('mark', ['line' => $this->toEstimate->getId(), 'status' => 'refused']);
        $render = $card->render();

        self::assertSame(BudgetLineStatus::Refused, $this->statusOf($this->toEstimate), 'a line is refused before anyone estimated it');
        self::assertNotNull($card->getEmittedEvent($render, 'budget:changed'));
        self::assertSame([$dialog.'?status=pending', $dialog.'?status=approved'], $asking($card), 'refused without a quantity, it still has none to be approved with');
    }

    public function testOnThePageOfItsBudgetTheCardSaysWhereItsDialogsLeadBack(): void
    {
        $links = fn (bool $standalone): array => $this->createLiveComponent('Budget:Card', ['budget' => $this->budget, 'standalone' => $standalone])
            ->actingAs($this->scene->user('thomas.girard@cairn.test'))
            ->render()->crawler()->filter('a[data-turbo-frame="modal"]')->each(static fn ($link): string => (string) $link->attr('href'));

        self::assertSame([], array_filter($links(false), static fn (string $href): bool => str_contains($href, 'from=')));
        self::assertSame([], array_filter($links(true), static fn (string $href): bool => !str_contains($href, 'from=budget')), 'new line, budget, each line and each status that asks for a quantity');
    }

    public function testTheBarShowsWhatWaitsAfterWhatWasConsumedAndNeverGoesPastItsEnd(): void
    {
        $bar = fn (): array => $this->card('claire.martin@maison-dupont.test')->render()->crawler()->filter('[role="progressbar"] > div')->each(
            static fn ($part): string => (str_contains((string) $part->attr('class'), 'amber') ? 'waits ' : 'consumed ').$part->attr('style'),
        );

        self::assertSame(['waits width: 40%', 'consumed width: 20%'], $bar(), 'two days consumed out of ten, then the two that wait, drawn under them from the start');

        $this->scene->line($this->budget, BudgetLineStatus::Pending, 20, label: 'Rewrite of the checkout');
        self::assertSame(['waits width: 100%', 'consumed width: 20%'], $bar(), 'more waits than is left');
    }

    public function testARefusalIsSaidOnTheCard(): void
    {
        $card = $this->card('thomas.girard@cairn.test');

        $card->call('mark', ['line' => $this->toEstimate->getId(), 'status' => 'approved']);
        $render = $card->render();

        self::assertStringContainsString('Give the line a quantity', $render->crawler()->filter('[role="alert"]')->text());
        self::assertSame(BudgetLineStatus::ToEstimate, $this->statusOf($this->toEstimate));
        self::assertNull($card->getEmittedEvent($render, 'budget:changed'));
    }

    public function testALineOfAnotherBudgetIsNotMarkedFromThisCard(): void
    {
        $other = $this->scene->line($this->scene->budget('Order 2026-10', 5), BudgetLineStatus::Pending, 1, label: 'Server upgrade');
        $card = $this->card('thomas.girard@cairn.test');

        $card->call('mark', ['line' => $other->getId(), 'status' => 'approved']);

        self::assertCount(1, $card->render()->crawler()->filter('[role="alert"]'));
        self::assertSame(BudgetLineStatus::Pending, $this->statusOf($other));
    }

    public function testAClientReadsTheLinesWithoutMarkingThem(): void
    {
        $card = $this->card('claire.martin@maison-dupont.test');
        $render = $card->render();
        $table = $render->crawler()->filter('table')->text();

        self::assertStringContainsString('MD-SPT-7', $table);
        self::assertStringContainsString('To approve', $table);
        self::assertStringContainsString('Private issue', $table, 'a line counts whatever its issue');
        self::assertStringNotContainsString('MD-SPT-6', (string) $render);
        self::assertCount(0, $render->crawler()->filter('[data-live-action-param="mark"]'));
        self::assertCount(0, $render->crawler()->filter('[data-turbo-frame="modal"]'));

        $card->call('mark', ['line' => $this->pending->getId(), 'status' => 'approved']);
        $render = $card->render();

        self::assertSame(BudgetLineStatus::Pending, $this->statusOf($this->pending), 'the command guards the writing');
        self::assertNull($card->getEmittedEvent($render, 'budget:changed'));
    }

    public function testTheDraftOfALineToEstimateShowsToWhoMayReadIt(): void
    {
        $this->scene->line($this->budget, BudgetLineStatus::ToEstimate, 3, label: 'Rewrite of the checkout');
        $row = fn (string $email): string => $this->card($email)->render()->crawler()->filter('tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Rewrite of the checkout'))->text();

        self::assertStringContainsString('3 d', $row('thomas.girard@cairn.test'));
        self::assertStringNotContainsString('3 d', $row('claire.martin@maison-dupont.test'), 'the client reads the line without what it may amount to');
        self::assertStringContainsString('—', $row('claire.martin@maison-dupont.test'));
    }

    public function testTheCardIsDrawnOnlyForWhoReadsTheBudgets(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->card('marc.petit@cairn.test')->render();
    }

    private function card(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Budget:Card', ['budget' => $this->budget])->actingAs($this->scene->user($email));
    }

    /**
     * The status of a line as the database has it now.
     */
    private function statusOf(BudgetLine $line): BudgetLineStatus
    {
        $stored = self::getContainer()->get(BudgetLineRepository::class)->find($line->getId());
        \assert($stored instanceof BudgetLine);

        return $stored->getStatus();
    }
}
