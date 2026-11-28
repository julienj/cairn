<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Release;

use App\Entity\Enum\ReleaseStatus;
use App\Message\Command\Release\UpdateRelease;
use App\Message\Query\Release\ListReleases;
use App\Message\Query\Release\ReleaseStep;
use App\Message\Query\Release\ReleaseSummary;
use App\Message\QueryBus;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function Symfony\Component\Clock\now;

/**
 * ListReleases on the shop of the scene: MD-SPT-6 is an internal task,
 * MD-SPT-5 is closed.
 */
final class ListReleasesTest extends KernelTestCase
{
    use KernelHelpers;

    public function testOpenReleasesComeFirstWithTheirIssuesCountedAsTheViewerSeesThem(): void
    {
        self::bootKernel();
        $scene = ShopScene::load(self::getContainer());
        $delivered = $scene->release('2.4.0', ['MD-SPT-5']);
        self::commandBus()->dispatch(new UpdateRelease((int) $scene->user('thomas.girard@cairn.test')->getId(), (int) $delivered->getId(), status: ReleaseStatus::Delivered));
        $scene->release('2.5.0', ['MD-SPT-3', 'MD-SPT-4', 'MD-SPT-6']);

        $forThomas = $this->list($scene, 'thomas.girard@cairn.test');
        self::assertSame(['2.5.0', '2.4.0'], array_map(static fn (ReleaseSummary $summary): string => $summary->release->getName(), $forThomas));
        self::assertSame(3, $forThomas[0]->total());
        self::assertSame(['open' => 0, 'in_progress' => 2, 'waiting' => 1, 'closed' => 0], $forThomas[0]->byStatus, 'the internal task is in progress');
        self::assertSame(100, $forThomas[1]->progress());

        $forClaire = $this->list($scene, 'claire.martin@maison-dupont.test');
        self::assertSame(2, $forClaire[0]->total(), 'the client does not count the internal task');
        self::assertSame(0, $forClaire[0]->progress());
    }

    public function testWhatIsLeftIsToldByStepInTheOrderOfTheInstanceWithWhatPresses(): void
    {
        self::bootKernel();
        $scene = ShopScene::load(self::getContainer());
        $thomas = (int) $scene->user('thomas.girard@cairn.test')->getId();
        // MD-SPT-1 is new and blocking, 3 in progress, 4 to test and high, 5 done, 6 an internal task in progress
        $release = $scene->release('2.5.0', ['MD-SPT-1', 'MD-SPT-3', 'MD-SPT-4', 'MD-SPT-5', 'MD-SPT-6']);

        $summary = $this->list($scene, 'thomas.girard@cairn.test')[0];
        self::assertSame(1, $summary->done());
        self::assertSame(4, $summary->remaining());
        self::assertSame(
            [['new', 1], ['in_progress', 2], ['to_test', 1]],
            array_map(static fn (ReleaseStep $step): array => [$step->place->id, $step->issues], $summary->steps),
            'the steps of what is not finished, as the instance declares them; the finished ones are the bar',
        );
        self::assertSame([1, 1], [$summary->blocking, $summary->high]);
        self::assertFalse($summary->late, 'no date, nothing to be late for');

        $forClaire = $this->list($scene, 'claire.martin@maison-dupont.test')[0];
        self::assertSame([['new', 1], ['in_progress', 1], ['to_test', 1]], array_map(static fn (ReleaseStep $step): array => [$step->place->id, $step->issues], $forClaire->steps), 'without the internal task');

        self::commandBus()->dispatch(new UpdateRelease($thomas, (int) $release->getId(), plannedOn: now()->modify('-1 day')));
        self::assertTrue($this->list($scene, 'thomas.girard@cairn.test')[0]->late, 'still open the day after the one planned');
        self::commandBus()->dispatch(new UpdateRelease($thomas, (int) $release->getId(), plannedOn: now()));
        self::assertFalse($this->list($scene, 'thomas.girard@cairn.test')[0]->late, 'the day itself is not late');
        self::commandBus()->dispatch(new UpdateRelease($thomas, (int) $release->getId(), plannedOn: now()->modify('-1 day'), status: ReleaseStatus::Delivered));
        self::assertFalse($this->list($scene, 'thomas.girard@cairn.test')[0]->late, 'a delivered release is late for nothing');
    }

    /**
     * @return list<ReleaseSummary>
     */
    private function list(ShopScene $scene, string $email): array
    {
        return self::getContainer()->get(QueryBus::class)->ask(new ListReleases($scene->user($email), $scene->shop));
    }
}
