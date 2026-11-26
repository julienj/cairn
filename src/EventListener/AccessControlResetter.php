<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Membership;
use App\Entity\TeamAccess;
use App\Entity\TeamMember;
use App\Security\AccessControl;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * AccessControl remembers the roles of the users it decided about during
 * the request: their memberships and the accesses of their teams. One
 * written in the meantime (an admin screen, the development story), or
 * someone joining or leaving a team, must be seen by the next decision.
 */
#[AsEntityListener(event: Events::postPersist, entity: Membership::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Membership::class)]
#[AsEntityListener(event: Events::postRemove, entity: Membership::class)]
#[AsEntityListener(event: Events::postPersist, entity: TeamAccess::class)]
#[AsEntityListener(event: Events::postUpdate, entity: TeamAccess::class)]
#[AsEntityListener(event: Events::postRemove, entity: TeamAccess::class)]
#[AsEntityListener(event: Events::postPersist, entity: TeamMember::class)]
#[AsEntityListener(event: Events::postRemove, entity: TeamMember::class)]
final class AccessControlResetter
{
    public function __construct(
        private readonly AccessControl $access,
    ) {
    }

    public function __invoke(Membership|TeamAccess|TeamMember $written): void
    {
        $this->access->reset();
    }
}
