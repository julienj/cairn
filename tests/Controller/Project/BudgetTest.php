<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Message\Command\Budget\UpdateBudget;
use App\Message\Command\Project\UpdateProject;
use App\Message\CommandBus;
use App\Repository\BudgetLineRepository;
use App\Repository\BudgetRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The budgets of the shop: Thomas manages Maison Dupont, Claire is its
 * client. The shop has no budget until its general settings give it a
 * unit. MD-SPT-8 is a feature, MD-SPT-6 an internal task.
 */
final class BudgetTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testAProjectWithoutAUnitHasNoBudgetPage(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('a[href="/MD/SPT/budgets"]');

        $this->client->request('GET', '/MD/SPT/budgets');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheManagerGivesTheProjectAUnitThenCreatesABudget(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/general');
        $this->client->submitForm('Save', ['project_settings[budgetUnit]' => 'days']);
        self::assertResponseRedirects('/MD/SPT/settings/general');
        $this->client->followRedirect();

        $this->client->clickLink('Budgets');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No open budget');
        $this->client->clickLink('New budget');
        self::assertRouteSame('app_project_budget_new');
        self::assertSelectorExists('turbo-frame#modal form[action="/MD/SPT/budgets/new"]', 'loaded over the list, the form is still sent to the dialog');

        $this->client->submitForm('Create the budget', ['budget[name]' => ' ', 'budget[volume]' => '10'], 'POST');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="dialog"]', 'the dialog stays open on the refusal');

        $this->client->submitForm('Create the budget', ['budget[name]' => 'Order 2026-01', 'budget[volume]' => '10.5']);
        $budget = $this->budget('Order 2026-01');
        self::assertSame(4410, $budget->getVolume(), 'ten days and a half, in minutes');
        self::assertResponseRedirects('/MD/SPT/budgets', null, 'a new budget shows with the others');
        $this->client->followRedirect();
        self::assertSelectorNotExists('[role="dialog"]');
        self::assertSelectorTextContains(\sprintf('main #budget-%d', $budget->getId()), 'Order 2026-01');
        self::assertSelectorTextContains(\sprintf('main #budget-%d', $budget->getId()), '10.5 d');

        $this->client->request('GET', '/MD/SPT/budgets/new');
        $this->client->submitForm('Create the budget', ['budget[name]' => 'Order 2026-01', 'budget[volume]' => '5']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="budget"]', 'already has a budget named Order 2026-01');
    }

    public function testTheUnitIsOnlyWhatIsShown(): void
    {
        // 6000 minutes are 14.29 days to two decimals, which would read back as 6002.
        $budget = $this->scene->budget('Order 2026-01', 6000 / 420);
        self::assertSame(6000, $budget->getVolume());
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', \sprintf('/MD/SPT/budgets/%d/edit', $budget->getId()));
        self::assertInputValueSame('budget[volume]', '14.29');
        $this->client->submitForm('Save', ['budget[name]' => 'Order 2026-01 bis']);
        self::assertResponseRedirects('/MD/SPT/budgets');
        self::assertSame(6000, $this->budget('Order 2026-01 bis')->getVolume(), 'renaming the budget leaves its volume to the minute');

        $this->client->request('GET', '/MD/SPT/settings/general');
        $this->client->submitForm('Save', ['project_settings[budgetUnit]' => 'hours']);
        $this->client->request('GET', '/MD/SPT/budgets');
        self::assertSelectorTextContains('main', '100 h', 'the same budget, read in hours');

        $this->client->request('GET', '/MD/SPT/settings/general');
        $this->client->submitForm('Save', ['project_settings[budgetUnit]' => '']);
        $this->client->request('GET', '/MD/SPT/budgets');
        self::assertResponseStatusCodeSame(404, 'without a unit the project shows no budget');
    }

    public function testAClientReadsTheBudgetsAndTheirLinesWithoutTheIssuesTheyDoNotSee(): void
    {
        $budget = $this->scene->budget('Order 2026-01', 10);
        $this->scene->line($budget, BudgetLineStatus::Approved, 1.5, 'MD-SPT-8');
        $this->scene->line($budget, BudgetLineStatus::Pending, 2, 'MD-SPT-7', 'Second instalment plan');
        $this->scene->line($budget, BudgetLineStatus::Approved, 0.5, 'MD-SPT-6');
        $this->scene->line($budget, BudgetLineStatus::ToEstimate, null, label: 'Audit of the performance');
        $this->scene->budget('Order 2026-10', 5);

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues');
        $this->client->clickLink('Budgets');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Open budgets 15 d 2 budgets', 'what the open budgets amount to together');
        self::assertSelectorTextContains('main', 'Left 13 d', 'fifteen days, less the two approved lines');
        self::assertSelectorNotExists('a[href="/MD/SPT/budgets/new"]');

        // The page of the budget shows the card the budgets page does.
        $card = \sprintf('main #budget-%d', $budget->getId());
        foreach (['/MD/SPT/budgets', \sprintf('/MD/SPT/budgets/%d', $budget->getId())] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorTextContains($card, '4 lines');
            self::assertSelectorTextContains($card, 'Left 8 d', 'ten days, less the two approved lines');
            self::assertSelectorTextContains($card.' table', 'MD-SPT-8');
            self::assertSelectorTextContains($card.' table', 'Second instalment plan');
            self::assertSelectorTextContains($card.' table', 'To approve');
            self::assertSelectorTextContains($card.' table', 'To estimate');
            self::assertSelectorTextContains($card.' table', 'Private issue');
            self::assertSelectorTextNotContains($card.' table', 'MD-SPT-6');
            self::assertSelectorTextNotContains($card.' table', 'Upgrade Symfony');
            self::assertSelectorNotExists('main a[data-turbo-frame="modal"]', 'a client changes nothing');
            self::assertSelectorNotExists('main [data-live-action-param="mark"]', 'nor marks the status of a line');
        }

        $this->client->request('GET', '/MD/SPT/budgets');
        $this->client->clickLink('Order 2026-01');
        self::assertRouteSame('app_project_budget');

        foreach (['/MD/SPT/budgets/new', \sprintf('/MD/SPT/budgets/%d/edit', $budget->getId()), \sprintf('/MD/SPT/budgets/%d/lines/new', $budget->getId())] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
        }

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/budgets');
        self::assertSelectorTextContains($card.' table', 'MD-SPT-6', 'the manager sees the internal task');
        self::assertSelectorExists($card.' [data-live-action-param="mark"]', 'and marks the status of a line from its menu');
    }

    public function testTheManagerChangesAndClosesABudgetFromItsDialog(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::Approved, 1, label: 'Server upgrade');
        $page = \sprintf('/MD/SPT/budgets/%d', $budget->getId());
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/budgets');
        $this->client->click($this->client->getCrawler()->filter('main a[aria-label="Edit"]')->link());
        self::assertRouteSame('app_project_budget_edit');
        self::assertInputValueSame('budget[volume]', '10');
        $this->client->submitForm('Save', ['budget[name]' => 'Order 2026-01 bis', 'budget[volume]' => '12', 'budget[closed]' => '1']);
        self::assertResponseRedirects('/MD/SPT/budgets');
        self::assertSame(5040, $this->budget('Order 2026-01 bis')->getVolume());
        self::assertFalse($this->budget('Order 2026-01 bis')->isOpen());

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'No open budget');
        self::assertSelectorTextContains('main', 'Closed budgets');
        self::assertSelectorNotExists('main table', 'the lines of a closed budget are read on its page');
        $this->client->clickLink('Order 2026-01 bis');
        self::assertRouteSame('app_project_budget');
        self::assertSelectorTextContains('main table', 'Server upgrade');
        self::assertSelectorNotExists(\sprintf('a[href="%s/lines/new"]', $page), 'a closed budget takes no new line');

        // A line of a closed budget is still put right, from the page of the budget.
        $this->client->click($this->client->getCrawler()->filter('main table a[data-turbo-frame="modal"]')->link());
        self::assertRouteSame('app_project_budget_line_edit', ['line' => (string) $line->getId()]);
        self::assertSelectorTextContains('main table', 'Server upgrade', 'the dialog opens over the page of the budget');
        $this->client->submitForm('Save', ['budget_line[quantity]' => '2']);
        self::assertResponseRedirects($page);
    }

    public function testAClosedBudgetIsDeletedFromItsDialog(): void
    {
        $budget = $this->scene->budget('Order 2026-01');
        $this->scene->line($budget, BudgetLineStatus::Approved, 1, 'MD-SPT-8', 'Second format');
        $kept = $this->scene->budget('Order 2026-10', 5);
        $page = \sprintf('/MD/SPT/budgets/%d', $budget->getId());
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', $page.'/edit');
        self::assertSelectorNotExists('form#budget-delete', 'an open budget is closed before it is deleted');
        $this->client->submitForm('Save', ['budget[closed]' => '1']);

        $this->client->request('GET', $page);
        $this->client->click($this->client->getCrawler()->filter('main a[aria-label="Edit"]')->link());
        self::assertSelectorTextContains('[role="dialog"]', 'A closed budget is deleted with all of its lines');
        $this->client->submitForm('Delete the budget');
        self::assertResponseRedirects('/MD/SPT/budgets', null, 'its page is gone: back to the budgets');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Budget Order 2026-01 deleted.');
        self::assertSelectorTextNotContains('main', 'Closed budgets');
        self::assertSame([], $this->lines($budget));
        self::assertSame(['Order 2026-10'], array_map(static fn (Budget $left): string => $left->getName(), self::getContainer()->get(BudgetRepository::class)->findByProject($this->scene->shop)));

        $this->client->request('GET', '/MD/SPT/issues/8');
        self::assertSelectorTextNotContains('main aside', 'Order 2026-01', 'the issue no longer shows the lines that went');

        // A client deletes nothing, closed or not.
        self::getContainer()->get(CommandBus::class)->dispatch(new UpdateBudget((int) $this->scene->user('thomas.girard@cairn.test')->getId(), (int) $kept->getId(), closed: true));
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('POST', \sprintf('/MD/SPT/budgets/%d/delete', $kept->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testADialogOpenedFromThePageOfABudgetLeadsBackThere(): void
    {
        $budget = $this->scene->budget();
        $page = \sprintf('/MD/SPT/budgets/%d', $budget->getId());
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', $page);
        $this->client->clickLink('New line');
        self::assertSelectorTextContains('main', 'Every line of this budget', 'the page of the budget stays behind the dialog');
        self::assertSelectorExists(\sprintf('[role="dialog"] a[href="%s"]', $page), 'closing the dialog leads back there');
        $this->client->submitForm('Add the line', ['budget_line[label]' => 'Server upgrade', 'budget_line[status]' => 'approved', 'budget_line[quantity]' => '1']);
        self::assertResponseRedirects($page, null, 'not to the budgets page, although the budget is open');

        $this->client->followRedirect();
        $this->client->click($this->client->getCrawler()->filter('main table a[data-turbo-frame="modal"]')->link());
        $this->client->submitForm('Save', ['budget_line[quantity]' => '2']);
        self::assertResponseRedirects($page);

        $this->client->followRedirect();
        $this->client->click($this->client->getCrawler()->filter('main a[aria-label="Edit"]')->link());
        self::assertRouteSame('app_project_budget_edit');
        $this->client->submitForm('Save', ['budget[volume]' => '12']);
        self::assertResponseRedirects($page);

        $this->client->followRedirect();
        $this->client->click($this->client->getCrawler()->filter('main table a[data-turbo-frame="modal"]')->link());
        $this->client->submitForm('Delete the line');
        self::assertResponseRedirects($page);
        self::assertSame([], $this->lines($budget));
    }

    public function testALineWithoutAQuantityIsGivenOneWhenItsStatusAsksForIt(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::ToEstimate, null, label: 'Audit of the performance');
        $dialog = \sprintf('/MD/SPT/budgets/%d/lines/%d', $budget->getId(), $line->getId());
        $this->signInAs('thomas.girard@cairn.test');

        // "Approved" in the menu of the line is a link to its dialog: the status is chosen, the quantity is asked.
        $this->client->request('GET', '/MD/SPT/budgets');
        $this->client->click($this->client->getCrawler()->filter(\sprintf('main table a[href="%s?status=approved"]', $dialog))->link());
        self::assertSelectorExists('select[name="budget_line[status]"] option[value="approved"][selected]');
        self::assertSelectorExists('input[name="budget_line[quantity]"][data-controller="autofocus"]', 'the dialog starts in the quantity');

        $this->client->submitForm('Save');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="dialog"]', 'Give the line a quantity');

        $this->client->submitForm('Save', ['budget_line[status]' => 'approved', 'budget_line[quantity]' => '0']);
        self::assertResponseRedirects('/MD/SPT/budgets');
        self::assertSame([BudgetLineStatus::Approved, 0], [$this->lines($budget)[0]->getStatus(), $this->lines($budget)[0]->getQuantity()], 'zero is a quantity');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', '0 d');

        // Refused, a line may lose its quantity; approved, it may not.
        $this->client->request('GET', $dialog);
        self::assertSelectorNotExists('input[data-controller="autofocus"]');
        $this->client->submitForm('Save', ['budget_line[status]' => 'refused', 'budget_line[quantity]' => '']);
        self::assertResponseRedirects('/MD/SPT/budgets');
        self::assertSame([BudgetLineStatus::Refused, null], [$this->lines($budget)[0]->getStatus(), $this->lines($budget)[0]->getQuantity()]);
    }

    public function testTheManagerWritesChangesAndDeletesALine(): void
    {
        $budget = $this->scene->budget();
        $page = \sprintf('/MD/SPT/budgets/%d', $budget->getId());
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/budgets');
        self::assertSelectorTextContains('main', 'No line yet');
        $this->client->clickLink('New line');
        self::assertRouteSame('app_project_budget_line_new');
        self::assertSelectorExists('[role="dialog"] [data-list-filter-url-value="/MD/SPT/budgets/issues"]', 'the issues are searched on the server, not listed in the page');

        $this->client->submitForm('Add the line', ['budget_line[status]' => 'pending', 'budget_line[quantity]' => '2']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="dialog"]', 'Choose an issue or write a label.');
        $this->client->submitForm('Add the line', ['budget_line[issue]' => (string) $this->scene->issue('MD-SPT-8')->getId(), 'budget_line[status]' => 'pending', 'budget_line[quantity]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="dialog"]', 'Give the line a quantity');

        $this->client->submitForm('Add the line', ['budget_line[issue]' => (string) $this->scene->issue('MD-SPT-8')->getId(), 'budget_line[status]' => 'pending', 'budget_line[quantity]' => '2']);
        self::assertResponseRedirects('/MD/SPT/budgets', null, 'the line shows on the budgets page, with its open budget');
        $line = $this->lines($budget)[0];
        self::assertSame(['MD-SPT-8', BudgetLineStatus::Pending, 840], [$line->getIssue()?->getKey(), $line->getStatus(), $line->getQuantity()]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', 'MD-SPT-8');
        self::assertSelectorTextContains('main', '2 d');
        $this->client->click($this->client->getCrawler()->filter('main table a[data-turbo-frame="modal"]')->link());
        self::assertRouteSame('app_project_budget_line_edit');
        self::assertSelectorNotExists('[name="budget_line[issue]"]', 'the issue of a line never changes');
        $this->client->submitForm('Save', ['budget_line[status]' => 'approved', 'budget_line[label]' => 'Agreed by phone']);
        self::assertResponseRedirects('/MD/SPT/budgets');
        $line = $this->lines($budget)[0];
        self::assertSame([BudgetLineStatus::Approved, 840, 'Agreed by phone'], [$line->getStatus(), $line->getQuantity(), $line->getLabel()]);

        $this->client->request('GET', \sprintf('%s/lines/%d', $page, $line->getId()));
        $this->client->submitForm('Save', ['budget_line[status]' => 'approved', 'budget_line[quantity]' => '']);
        self::assertResponseStatusCodeSame(422, 'an emptied quantity is not "keep the one it had"');

        $this->client->request('GET', \sprintf('%s/lines/%d', $page, $line->getId()));
        $this->client->submitForm('Delete the line');
        self::assertResponseRedirects('/MD/SPT/budgets');
        self::assertSame([], $this->lines($budget));
    }

    public function testTheBudgetsOfAnArchivedProjectAreStillReadAndNoLongerChanged(): void
    {
        $budget = $this->scene->budget('Order 2026-01', 10);
        $this->scene->line($budget, BudgetLineStatus::Approved, 1.5, 'MD-SPT-8');
        self::getContainer()->get(CommandBus::class)->dispatch(new UpdateProject((int) $this->scene->shop->getId(), archived: true));

        foreach (['claire.martin@maison-dupont.test', 'thomas.girard@cairn.test'] as $email) {
            $this->signInAs($email);
            $this->client->request('GET', '/MD/SPT/issues');
            $this->client->clickLink('Budgets');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Order 2026-01');
            self::assertSelectorTextContains('main table', 'MD-SPT-8');
            self::assertSelectorNotExists('main a[data-turbo-frame="modal"]', 'nobody changes an archived project');
            self::assertSelectorNotExists('main [data-live-action-param="mark"]', 'nor marks the status of a line');
            $this->client->request('GET', \sprintf('/MD/SPT/budgets/%d', $budget->getId()));
            self::assertSelectorTextContains('main table', 'MD-SPT-8');
            $this->client->request('GET', '/MD/SPT/issues/8');
            self::assertSelectorTextContains('main aside', 'Order 2026-01');
        }

        $this->client->request('GET', '/MD/SPT/budgets/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheIssueOfALineIsSearchedAmongThoseOfTheProject(): void
    {
        $budget = $this->scene->budget();
        $elsewhere = (string) $this->scene->intranetBug()->getId();
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/budgets/issues');
        self::assertResponseIsSuccessful();
        $offered = $this->client->getCrawler()->filter('[data-list-filter-target="item"]');
        self::assertCount(10, $offered, 'every issue of the shop, closed ones too');
        self::assertSelectorExists('[data-picker-label-param^="MD-SPT-5 "]', 'closed issues are offered too');
        self::assertSelectorNotExists('p', 'nothing more to find');

        $crawler = $this->client->request('GET', '/MD/SPT/budgets/issues?q=8');
        $item = $crawler->filter('[data-picker-value-param="'.$this->scene->issue('MD-SPT-8')->getId().'"]');
        self::assertCount(1, $item, 'found by its number, and chosen by its id');
        self::assertStringStartsWith('MD-SPT-8 · ', (string) $item->attr('data-picker-label-param'));
        self::assertCount(0, $crawler->filter('[data-picker-label-param^="MD-SPT-2 "]'));

        $this->client->request('GET', '/MD/SPT/budgets/issues?q=nothing+is+called+that');
        self::assertSelectorNotExists('[data-list-filter-target="item"]');

        // An issue of another project is not one of the choices, whatever the request says.
        $this->client->request('GET', \sprintf('/MD/SPT/budgets/%d/lines/new', $budget->getId()));
        $this->client->submitForm('Add the line', ['budget_line[issue]' => $elsewhere, 'budget_line[status]' => 'pending', 'budget_line[quantity]' => '2']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->lines($budget));
        foreach (['99999999999', 'MD-SPT-8', '-1'] as $notAnId) {
            $this->client->submitForm('Add the line', ['budget_line[issue]' => $notAnId, 'budget_line[status]' => 'pending', 'budget_line[quantity]' => '2']);
            self::assertResponseStatusCodeSame(422, \sprintf('"%s" is refused like any issue that is not offered', $notAnId));
        }
        self::assertCount(0, $this->lines($budget));

        // A refused line is drawn again with the issue that was chosen.
        $this->client->submitForm('Add the line', ['budget_line[issue]' => (string) $this->scene->issue('MD-SPT-8')->getId(), 'budget_line[status]' => 'pending', 'budget_line[quantity]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="dialog"] [data-picker-target="label"]', 'MD-SPT-8');

        // Searching is for whoever writes the lines.
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/budgets/issues');
        self::assertResponseStatusCodeSame(403);
    }

    public function testWhatAFormCannotTake(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::Approved, 1, label: 'Server upgrade');
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/budgets/new');
        $this->client->submitForm('Create the budget', ['budget[name]' => 'Order 2026-10', 'budget[volume]' => '9999999']);
        self::assertResponseStatusCodeSame(422, 'a volume that would not fit once in minutes');
        $this->client->request('GET', \sprintf('/MD/SPT/budgets/%d/lines/new', $budget->getId()));
        $this->client->submitForm('Add the line', ['budget_line[label]' => 'Too much', 'budget_line[status]' => 'approved', 'budget_line[quantity]' => '40000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="dialog"] [data-picker-value-param=""]', 'an issue picked by mistake is taken back');

        // A status left out by a request written by hand is a refusal like another.
        $form = $this->client->getCrawler()->selectButton('Add the line')->form();
        $values = $form->getPhpValues();
        $values['budget_line']['label'] = 'No status';
        unset($values['budget_line']['status']);
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);

        // Without a unit the project takes no budget activity, from a dialog left open either.
        $this->client->request('GET', \sprintf('/MD/SPT/budgets/%d/lines/%d', $budget->getId(), $line->getId()));
        self::getContainer()->get(CommandBus::class)->dispatch(new UpdateProject((int) $this->scene->shop->getId(), withoutBudgets: true));
        $this->client->submitForm('Delete the line');
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->lines($budget));
    }

    public function testADialogIsAnsweredAloneToItsFrame(): void
    {
        $budget = $this->scene->budget();
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/budgets/new', server: ['HTTP_TURBO_FRAME' => 'modal']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal [role="dialog"]');
        self::assertSelectorNotExists('nav', 'the page behind is not drawn again');

        foreach (['/MD/SPT/budgets', \sprintf('/MD/SPT/budgets/%d', $budget->getId())] as $page) {
            $this->client->request('GET', $page, server: ['HTTP_TURBO_FRAME' => 'modal']);
            self::assertSelectorNotExists('[role="dialog"]', 'the page a dialog redirects to answers the frame its empty self');
        }
    }

    public function testABudgetOfAnotherProjectIsNotFound(): void
    {
        $budget = $this->scene->budget();
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', \sprintf('/MD/INT/budgets/%d', $budget->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePageOfAnIssueShowsTheLinesThatNameIt(): void
    {
        $budget = $this->scene->budget('Order 2026-01', 10);
        $this->scene->line($budget, BudgetLineStatus::Pending, 1.5, 'MD-SPT-8', 'Second format');
        $this->scene->line($budget, BudgetLineStatus::Approved, 1, 'MD-SPT-8');
        $this->scene->line($budget, BudgetLineStatus::Refused, 3, 'MD-SPT-8', 'Third format');

        foreach (['thomas.girard@cairn.test', 'claire.martin@maison-dupont.test'] as $email) {
            $this->signInAs($email);
            $this->client->request('GET', '/MD/SPT/issues/8');
            self::assertSelectorTextContains('main aside', 'Order 2026-01');
            self::assertSelectorTextContains('main aside', 'Budget 2.5 d', 'the time of the issue: what is approved and what waits, not what was refused');
            self::assertSelectorTextContains('main aside', 'To approve');
            self::assertSelectorTextContains('main aside', '1.5 d');
            self::assertSelectorTextContains('main aside', 'Second format');
            self::assertSelectorExists(\sprintf('main aside a[href="/MD/SPT/budgets/%d"]', $budget->getId()));
        }

        $this->client->request('GET', '/MD/SPT/issues/1');
        self::assertSelectorTextNotContains('main aside', 'Order 2026-01', 'no line names this issue');
    }

    private function budget(string $name): Budget
    {
        $budget = self::getContainer()->get(BudgetRepository::class)->findOneByName($this->scene->shop, $name);
        \assert($budget instanceof Budget);

        return $budget;
    }

    /**
     * @return list<BudgetLine>
     */
    private function lines(Budget $budget): array
    {
        return self::getContainer()->get(BudgetLineRepository::class)->findByBudget($budget);
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}
