<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
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
     * The tokens of a user that still sign requests, with their site and
     * its project, the last one given first: where the account is linked.
     *
     * @return list<WidgetToken>
     */
    public function findUsableByUser(User $user, \DateTimeImmutable $now): array
    {
        /** @var list<WidgetToken> $tokens */
        $tokens = $this->createQueryBuilder('t')
            ->addSelect('s', 'p', 'w')
            ->join('t.site', 's')
            ->join('s.project', 'p')
            ->join('p.workspace', 'w')
            ->where('t.user = :user')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now, 'datetime_immutable')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $tokens;
    }

    public function hasUsable(User $user, \DateTimeImmutable $now): bool
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.user = :user')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now, 'datetime_immutable')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Removes every token of a user, in one statement: the account is
     * linked nowhere any more, which is what a change of its password or
     * of its second factor asks for.
     */
    public function removeOfUser(User $user): void
    {
        $this->getEntityManager()->createQuery(\sprintf('DELETE FROM %s t WHERE t.user = :user', WidgetToken::class))
            ->execute(['user' => $user->getId()]);
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
