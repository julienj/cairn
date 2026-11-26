<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Issue;

use App\Entity\User;
use App\Message\Query\Issue\ListSubscribers;
use App\Repository\IssueSubscriptionRepository;
use App\Security\AccessControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The same filter as NotifyIssueChangeHandler applies before writing:
 * what the list shows is who will be written to.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListSubscribersHandler
{
    public function __construct(
        private readonly IssueSubscriptionRepository $subscriptions,
        private readonly AccessControl $access,
    ) {
    }

    /**
     * @return list<User>
     */
    public function __invoke(ListSubscribers $query): array
    {
        return array_values(array_filter(
            $this->subscriptions->findSubscribers($query->issue),
            fn (User $user): bool => $this->access->canFollow($user, $query->issue),
        ));
    }
}
