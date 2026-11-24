<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\WidgetToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WidgetToken>
 */
class WidgetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetToken::class);
    }

    public function findOneBySecret(#[\SensitiveParameter] string $secret): ?WidgetToken
    {
        return $this->findOneBy(['hash' => WidgetToken::hash($secret)]);
    }

    /**
     * Removes the tokens given for a site of a project, in one statement.
     */
    public function removeOfSite(Project $project, string $origin): void
    {
        $this->getEntityManager()->createQuery(\sprintf('DELETE FROM %s t WHERE t.project = :project AND t.origin = :origin', WidgetToken::class))
            ->execute(['project' => $project->getId(), 'origin' => $origin]);
    }

    /**
     * Removes the tokens nobody can use any more.
     */
    public function removeExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->createQuery(\sprintf('DELETE FROM %s t WHERE t.expiresAt <= :now', WidgetToken::class))
            ->setParameter('now', $now, 'datetime_immutable')
            ->execute();
    }
}
