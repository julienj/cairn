<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Release;

use App\Config\ConfigRegistry;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Release;
use App\Message\Query\Release\ListReleases;
use App\Message\Query\Release\ReleaseStep;
use App\Message\Query\Release\ReleaseSummary;
use App\Repository\ReleaseRepository;
use App\Security\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The releases, then their issues counted by release, step and criticality
 * under the visibility of the issue lists: an external user does not count
 * the private issues they would not find in the list. A step is read from
 * the definition, which gives its status and the order the steps are told
 * in.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListReleasesHandler
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
        private readonly ConfigRegistry $config,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<ReleaseSummary>
     */
    public function __invoke(ListReleases $query): array
    {
        $releases = $this->releases->findByProject($query->project);
        if ([] === $releases) {
            return [];
        }

        $counts = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.release) AS releaseId', 'step.id AS placeId', 'i.criticality AS criticality', 'COUNT(i.id) AS issues')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->join('i.place', 'step')
            ->where('i.project = :project')
            ->andWhere('i.release IS NOT NULL')
            ->groupBy('i.release', 'step.id', 'i.criticality')
            ->setParameter('project', $query->project);
        $this->visibility->restrictIssues($counts, $query->viewer);

        $places = $this->config->definition()->places;
        $byPlace = [];
        $urgent = [];
        foreach ($counts->getQuery()->getArrayResult() as $row) {
            $release = (int) $row['releaseId'];
            $place = (string) $row['placeId'];
            $criticality = $row['criticality'] instanceof Criticality ? $row['criticality'] : Criticality::from((string) $row['criticality']);
            $byPlace[$release][$place] = ($byPlace[$release][$place] ?? 0) + (int) $row['issues'];
            if (isset($places[$place]) && PlaceStatus::Closed !== $places[$place]->status && \in_array($criticality, [Criticality::Blocking, Criticality::High], true)) {
                $urgent[$release][$criticality->value] = ($urgent[$release][$criticality->value] ?? 0) + (int) $row['issues'];
            }
        }

        $today = $this->clock->now()->setTime(0, 0);

        return array_map(
            static function (Release $release) use ($places, $byPlace, $urgent, $today): ReleaseSummary {
                $id = (int) $release->getId();
                $byStatus = array_fill_keys(array_map(static fn (PlaceStatus $status): string => $status->value, PlaceStatus::cases()), 0);
                $steps = [];
                // in the order of the definition: the one the instance declares its steps in
                foreach ($places as $place) {
                    $issues = $byPlace[$id][$place->id] ?? 0;
                    if (0 === $issues) {
                        continue;
                    }
                    $byStatus[$place->status->value] += $issues;
                    if (PlaceStatus::Closed !== $place->status) {
                        $steps[] = new ReleaseStep($place, $issues);
                    }
                }

                return new ReleaseSummary(
                    $release,
                    $byStatus,
                    $steps,
                    $urgent[$id][Criticality::Blocking->value] ?? 0,
                    $urgent[$id][Criticality::High->value] ?? 0,
                    $release->isOpen() && null !== $release->getPlannedOn() && $release->getPlannedOn() < $today,
                );
            },
            $releases,
        );
    }
}
