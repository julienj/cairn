<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Board;

use App\Entity\Team;
use App\Entity\Tracker;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use App\Twig\Components\Board\Column;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The columns of the bug kanban of the scene: MD-SPT-1 and 2 are new
 * (blocking and low), 3 is in progress, 4 waits for acceptance, 5 is done.
 */
final class ColumnTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testAColumnShowsTheIssuesOfItsPlaceWithWhereEachMayGo(): void
    {
        $render = (string) $this->column('new', 'thomas.girard@cairn.test')->render();

        self::assertStringContainsString('data-board-key="MD-SPT-1"', $render);
        self::assertStringContainsString('data-board-key="MD-SPT-2"', $render);
        self::assertStringNotContainsString('MD-SPT-3', $render);
        self::assertStringContainsString('data-board-places="needs_info qualified in_progress blocked to_test to_release done declined"', $render, 'who moves freely drops a card on any other place');
        self::assertStringContainsString('data-board-places="needs_info qualified in_progress declined"', (string) $this->column('new', 'nadia.benali@cairn.test')->render(), 'a developer, held to the workflow, where her actions lead');
    }

    public function testACardShowsTheTeamItsIssueIsGivenToAsASquare(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(Team::class, $team);
        $commands->dispatch(new GrantTeamAccess((int) $team->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $commands->dispatch(new UpdateIssue((int) $this->scene->user('thomas.girard@cairn.test')->getId(), 'MD-SPT-1', assigneeTeamId: $team->getId()));

        $render = (string) $this->column('new', 'thomas.girard@cairn.test')->render();

        $card = explode('data-board-key="MD-SPT-2"', explode('data-board-key="MD-SPT-1"', $render)[1])[0];
        self::assertStringContainsString('title="Hosting"', $card);
        self::assertStringContainsString('rounded-md', $card, 'a team is a square where a person is a disc');
    }

    public function testACardTheViewerCannotMoveIsLocked(): void
    {
        $render = (string) $this->column('new', 'claire.martin@maison-dupont.test')->render();

        self::assertStringContainsString('data-board-places=""', $render);
        self::assertStringContainsString('board-locked', $render);
    }

    public function testOnlyWhoeverMayRankSortsTheColumn(): void
    {
        self::assertStringContainsString('data-board-rank-value="true"', (string) $this->column('new', 'thomas.girard@cairn.test')->render());
        self::assertStringContainsString('data-board-rank-value="false"', (string) $this->column('new', 'claire.martin@maison-dupont.test')->render());
    }

    public function testTheLatestArrivalIsOnTop(): void
    {
        $this->scene->bug('Just in');

        self::assertMatchesRegularExpression('/^[^$]*?data-board-key="(?!MD-SPT-[12]")/', (string) $this->column('new', 'thomas.girard@cairn.test')->render(), 'before the two issues the scene starts with');
    }

    public function testTheFiltersOfTheAddressApplyAsTheyDoToTheList(): void
    {
        $render = (string) $this->column('new', 'thomas.girard@cairn.test', ['query' => ['criticality' => ['blocking'], 'places' => ['done'], 'group' => 'assignee', 'sort' => 'title']])->render();

        self::assertStringContainsString('MD-SPT-1', $render, 'the place filter, the grouping and the sort belong to the list');
        self::assertStringNotContainsString('MD-SPT-2', $render);

        $render = (string) $this->column('new', 'thomas.girard@cairn.test', ['query' => ['q' => 'footer']])->render();
        self::assertStringContainsString('MD-SPT-2', $render);
        self::assertStringNotContainsString('MD-SPT-1"', $render);
    }

    public function testTheCardsShowTheirTagsAndTheColumnFiltersByThem(): void
    {
        $tag = $this->scene->tag('Back office', keys: ['MD-SPT-2']);

        $render = (string) $this->column('new', 'claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('Back office', $render);
        self::assertStringContainsString('MD-SPT-1', $render);

        $render = (string) $this->column('new', 'claire.martin@maison-dupont.test', ['query' => ['tags' => [(string) $tag->getId()]]])->render();
        self::assertStringContainsString('MD-SPT-2', $render);
        self::assertStringNotContainsString('MD-SPT-1', $render);
    }

    public function testACardOpensItsIssueFromAnywhereAndCountsItsTagsPastTheSecond(): void
    {
        foreach (['Back office', 'Front office', 'Mobile'] as $name) {
            $this->scene->tag($name, keys: ['MD-SPT-2']);
        }

        $crawler = $this->column('new', 'thomas.girard@cairn.test')->render()->crawler();
        $card = $crawler->filter('#board-card-'.$this->scene->issue('MD-SPT-2')->getId());

        self::assertCount(1, $card->filter('a'), 'one link, the title, stretched over the card');
        self::assertStringContainsString('after:absolute', (string) $card->filter('a')->attr('class'));
        self::assertSame('/MD/SPT/issues/2', $card->filter('a')->attr('href'));
        self::assertStringContainsString('Back office', $card->text());
        self::assertStringContainsString('Front office', $card->text());
        self::assertStringNotContainsString('Mobile', $card->text());
        self::assertSame('+1', $card->filter('span[title="Mobile"]')->text(), 'the rest is counted, and named on hover');
    }

    public function testAnEmptyColumnSaysSo(): void
    {
        self::assertStringContainsString('No issue', (string) $this->column('blocked', 'thomas.girard@cairn.test')->render());
    }

    public function testAColumnGrowsByBatches(): void
    {
        foreach (range(1, Column::BATCH + 5) as $index) {
            $this->scene->bug("Bug {$index}");
        }
        $column = $this->column('new', 'thomas.girard@cairn.test');

        self::assertSame(Column::BATCH, substr_count((string) $column->render(), 'data-board-key='));
        self::assertStringContainsString('Show more (7)', (string) $column->render());

        $column->call('more');
        self::assertSame(Column::BATCH + 7, substr_count((string) $column->render(), 'data-board-key='));
        self::assertStringNotContainsString('Show more', (string) $column->render());
    }

    public function testAFoldedColumnIsARailThatCountsItsIssuesUntilUnfolded(): void
    {
        $column = $this->column('done', 'thomas.girard@cairn.test', ['open' => false]);
        $render = (string) $column->render();
        self::assertStringNotContainsString('data-board-key="MD-SPT-5"', $render);
        self::assertStringContainsString('w-10', $render);
        self::assertMatchesRegularExpression('#>\s*1\s*</span>#', $render, 'the rail still counts its issues');
        self::assertStringContainsString('data-board-place-value="done"', $render, 'a card can still be dropped on it');

        $column->call('toggle');
        $render = (string) $column->render();
        self::assertStringContainsString('data-board-key="MD-SPT-5"', $render);
        self::assertStringContainsString('w-72', $render);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function column(string $place, string $email, array $props = []): TestLiveComponent
    {
        return $this->createLiveComponent('Board:Column', ['tracker' => $this->bugs(), 'placeId' => $place, ...$props])
            ->actingAs($this->scene->user($email));
    }

    private function bugs(): Tracker
    {
        return $this->scene->bugs;
    }
}
