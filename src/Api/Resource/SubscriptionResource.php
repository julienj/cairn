<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Api\Resource\Value\NamedRef;
use App\Api\State\SubscriptionProcessor;
use App\Api\State\SubscriptionProvider;

/**
 * Whether the user follows an issue, that is receives a mail for what
 * happens to it. PUT follows, which takes a role on the project of the
 * issue, DELETE stops; both may be repeated.
 */
#[ApiResource(
    shortName: 'Subscription',
    operations: [
        new Get(uriTemplate: '/issues/{issue}/subscription', provider: SubscriptionProvider::class),
        new Put(
            uriTemplate: '/issues/{issue}/subscription',
            description: 'Follows the issue. Takes no body.',
            deserialize: false,
            validate: false,
            provider: SubscriptionProvider::class,
            processor: SubscriptionProcessor::class,
        ),
        new Delete(
            uriTemplate: '/issues/{issue}/subscription',
            description: 'Stops following the issue.',
            provider: SubscriptionProvider::class,
            processor: SubscriptionProcessor::class,
        ),
    ],
)]
final readonly class SubscriptionResource
{
    public function __construct(
        /** Key of the issue. */
        #[ApiProperty(identifier: true)]
        public string $issue,
        /** Whether a mail comes for what happens to the issue: by following it oneself, or through a team. */
        public bool $following,
        /** Since when the user follows it themselves; absent when they only do through a team. */
        public ?\DateTimeImmutable $since = null,
        /**
         * The teams of the user that follow the issue: DELETE does not stop those.
         *
         * @var list<NamedRef>
         */
        public array $teams = [],
    ) {
    }
}
