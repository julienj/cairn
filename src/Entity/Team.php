<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * People who answer as one: the hosting team, the accounting department
 * of a client. A team is internal or external, for good: an internal team
 * takes internal users only, an external one takes anyone. Its name is
 * what people choose it by, so no two teams share one.
 */
#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_team_name', columns: ['name'])]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private bool $internal;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, bool $internal)
    {
        $this->name = trim($name);
        $this->internal = $internal;
        $this->createdAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
