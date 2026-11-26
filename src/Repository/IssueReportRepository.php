<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use App\Entity\IssueReport;
use App\Widget\TechnicalReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssueReport>
 */
class IssueReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssueReport::class);
    }

    /**
     * Whether the issue was reported with a report, without reading it.
     */
    public function has(Issue $issue): bool
    {
        return $this->count(['issue' => $issue]) > 0;
    }

    /**
     * The report the issue was reported with, null when it has none.
     */
    public function reportOf(Issue $issue): ?TechnicalReport
    {
        return $this->findOneBy(['issue' => $issue])?->read();
    }
}
