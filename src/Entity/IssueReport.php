<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueReportRepository;
use App\Widget\TechnicalReport;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the widget noted on the site an issue was reported from (the
 * page, the browser, the errors): a fact of the creation of the issue,
 * kept as Widget/TechnicalReport tamed it and never changed afterwards.
 * Apart from the issue, so that only its page reads it, and gone with it.
 *
 * What is kept is never rewritten: a report is read again through
 * TechnicalReport::from(), which takes every shape it ever wrote.
 */
#[ORM\Entity(repositoryClass: IssueReportRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_issue_report_issue', columns: ['issue_id'])]
class IssueReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    public function __construct(Issue $issue, TechnicalReport $report)
    {
        $this->issue = $issue;
        $this->data = $report->toArray();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    /**
     * The report as it reads today, null when nothing of what was kept can be read any more.
     */
    public function read(): ?TechnicalReport
    {
        return TechnicalReport::from($this->data);
    }
}
