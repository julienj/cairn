<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Repository\IssueRepository;
use App\Workflow\HasPlace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * The tracked object. Its key (`MD-SPT-128`) is fixed at creation. It
 * stands on a place from the first moment; whether it is open, waiting or
 * closed is the status of that place, read from the configuration and
 * never copied here. `closedAt` is a fact of its own: when it was last
 * moved to a closed place.
 */
#[ORM\Entity(repositoryClass: IssueRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_issue_project_number', columns: ['project_id', 'number'])]
#[ORM\Index(name: 'idx_issue_project_place', columns: ['project_id', 'place'])]
#[ORM\Index(name: 'idx_issue_assignee_place', columns: ['assignee_id', 'place'])]
#[ORM\Index(name: 'idx_issue_board', columns: ['tracker_id', 'place', 'board_rank'])]
#[ORM\Index(name: 'idx_issue_imported_ref', columns: ['imported_ref'])]
class Issue implements HasPlace
{
    /** The gap left between two issues ranked one after the other. */
    public const int RANK_STEP = 65_536;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Tracker $tracker;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(name: '`key`', length: 20, unique: true)]
    private string $key;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** The step the issue stands on, an element of the configuration: only its key is read from here. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'place', nullable: false)]
    private Place $place;

    /** Where the issue stands in its kanban column, the highest first; compared within one tracker and one place only. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $boardRank = 0;

    #[ORM\Column(length: 20, enumType: Criticality::class)]
    private Criticality $criticality = Criticality::DEFAULT;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    #[ORM\ManyToOne]
    private ?User $assignee = null;

    #[ORM\ManyToOne]
    private ?Release $release = null;

    #[ORM\Column]
    private bool $private = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * When someone last acted on the issue. `updatedAt` moves with every
     * change, those of the automation rules included; this one only when a
     * person (or a forge) acted, so that "nothing happened for 30 days"
     * is not reset by the reminder a rule wrote. Null on the issues written
     * before the column existed: `updatedAt` then stands for it.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** What named the issue in the tracker it was imported from: searched, shown as "formerly #4812". */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $importedRef = null;

    /**
     * What the widget noted on the site the issue was reported from (the
     * page, the browser, the errors): a fact of the creation, as
     * Widget/TechnicalReport tamed it, never changed afterwards.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $report = null;

    /**
     * @param Place $place where the issue starts, the initial place of the tracker's workflow
     */
    public function __construct(Project $project, Tracker $tracker, Place $place, int $number, User $author, string $title, string $description)
    {
        $this->project = $project;
        $this->tracker = $tracker;
        $this->number = $number;
        $this->key = $project->getIssuePrefix().'-'.$number;
        $this->author = $author;
        $this->title = trim($title);
        $this->description = $description;
        $this->createdAt = now();
        $this->updatedAt = $this->createdAt;
        $this->lastActivityAt = $this->createdAt;
        $this->enter($place, false);
    }

    /**
     * An issue brought from another tracker as it stood there: its place,
     * its properties and its dates, from its creation to its last change.
     */
    public static function imported(
        Project $project,
        Tracker $tracker,
        Place $place,
        int $number,
        User $author,
        string $title,
        string $description,
        Criticality $criticality,
        ?User $assignee,
        ?Release $release,
        bool $private,
        string $importedRef,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $closedAt,
    ): self {
        $issue = new self($project, $tracker, $place, $number, $author, $title, $description);
        $issue->criticality = $criticality;
        $issue->assignee = $assignee;
        $issue->release = $release;
        $issue->private = $private;
        $issue->importedRef = $importedRef;
        $issue->createdAt = $createdAt;
        $issue->updatedAt = max($createdAt, $updatedAt);
        $issue->lastActivityAt = $issue->updatedAt;
        $issue->closedAt = PlaceStatus::Closed === $place->getStatus() ? ($closedAt ?? $issue->updatedAt) : null;

        return $issue;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImportedRef(): ?string
    {
        return $this->importedRef;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getTracker(): Tracker
    {
        return $this->tracker;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * The key of the step the issue stands on.
     */
    public function getPlace(): string
    {
        return $this->place->getId();
    }

    /**
     * Enters a place. Moved from an open place to a closed one, the issue
     * is closed now; moved to an open one, it no longer has a closing date.
     * Between two closed places the date stays.
     */
    public function moveTo(Place $place): void
    {
        $this->enter($place, PlaceStatus::Closed === $this->place->getStatus());
    }

    private function enter(Place $place, bool $wasClosed): void
    {
        $this->place = $place;
        $this->closedAt = match (true) {
            PlaceStatus::Closed !== $place->getStatus() => null,
            $wasClosed => $this->closedAt ?? now(),
            default => now(),
        };
        $this->touch();
    }

    /**
     * Re-categorises the issue: the workflow changes, so the place is chosen
     * explicitly. Values of fields the new workflow does not use are kept.
     */
    public function changeTracker(Tracker $tracker, Place $place): void
    {
        $this->tracker = $tracker;
        $this->moveTo($place);
    }

    public function getBoardRank(): int
    {
        return $this->boardRank;
    }

    /**
     * Places the issue in its kanban column. Not an activity on the issue:
     * nothing else changes.
     */
    public function rankAt(int $rank): void
    {
        $this->boardRank = $rank;
    }

    public function getCriticality(): Criticality
    {
        return $this->criticality;
    }

    public function setCriticality(Criticality $criticality): void
    {
        $this->criticality = $criticality;
        $this->touch();
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function assignTo(?User $assignee): void
    {
        $this->assignee = $assignee;
        $this->touch();
    }

    /**
     * Hidden from external users, either by its own flag or because its
     * tracker is private.
     */
    public function getRelease(): ?Release
    {
        return $this->release;
    }

    /**
     * Schedules the issue in a release of its project, or in none.
     */
    public function scheduleIn(?Release $release): void
    {
        $this->release = $release;
        $this->touch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getReport(): ?array
    {
        return $this->report;
    }

    /**
     * @param array<string, mixed> $report what TechnicalReport::toArray() gives
     */
    public function attachReport(array $report): void
    {
        $this->report = $report;
    }

    public function isPrivate(): bool
    {
        return $this->private || $this->tracker->isPrivate();
    }

    public function makePrivate(): void
    {
        $this->private = true;
        $this->touch();
    }

    public function makePublic(): void
    {
        $this->private = false;
        $this->touch();
    }

    public function retitle(string $title): void
    {
        $this->title = trim($title);
        $this->touch();
    }

    public function describe(string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getLastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt ?? $this->updatedAt;
    }

    /**
     * Records activity on the issue (a comment, for instance).
     */
    public function touch(): void
    {
        // An issue older than `lastActivityAt` takes it from `updatedAt` before that one moves.
        $this->lastActivityAt ??= $this->updatedAt;
        $this->updatedAt = now();
    }

    /**
     * Someone acted on the issue, not an automation rule.
     */
    public function markActivity(): void
    {
        $this->lastActivityAt = now();
    }
}
