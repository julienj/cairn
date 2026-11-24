<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use App\Entity\Enum\ActivityKind;
use App\Entity\Enum\AuthMethod;
use App\Entity\Enum\MailGrouping;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes an account, PATCH style: a property left null keeps its value;
 * an empty `company` clears it. An administrator is always internal; a
 * deactivated account can no longer sign in but keeps its memberships.
 * Nobody sets a password for someone else: the person chooses it from
 * an invitation or a reset link. Without an actor the administration
 * asks; with one, the person changes their own account from its pages,
 * and only how their mails are grouped and what their threads hide: who they are (name, email,
 * company, type) stays the administration's, and so does how they sign
 * in (`authMethod`): told to sign in through the identity provider, an
 * account loses its password and the links still out; back to a
 * password, it waits for an invitation. Handled by UpdateUserHandler.
 */
#[Assert\Expression(
    'this.admin !== true or this.internal !== false',
    message: 'An administrator is internal.',
)]
final readonly class UpdateUser
{
    public function __construct(
        public int $userId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public ?string $email = null,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 80)]
        public ?string $firstName = null,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 80)]
        public ?string $lastName = null,
        #[Assert\Length(max: 120)]
        public ?string $company = null,
        public ?bool $internal = null,
        public ?bool $admin = null,
        public ?bool $active = null,
        public ?MailGrouping $mailGrouping = null,
        /** @var list<ActivityKind>|null the kinds of activity left out of the thread of every issue; empty shows all */
        #[Assert\All(new Assert\Type(ActivityKind::class))]
        public ?array $hiddenActivity = null,
        public ?AuthMethod $authMethod = null,
        public ?int $actorId = null,
    ) {
    }
}
