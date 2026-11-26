<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\WidgetToken;
use App\Repository\WidgetTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Authenticates a request of the `widget` firewall from its bearer token:
 * a token of ours, not expired, given for a site of the project the
 * address names: a token goes with its site, so one that is found still
 * has it (the firewall's UserChecker refuses a deactivated account). Every refusal reads the same from
 * outside. The token found is left on the request, where tokenOf() reads
 * it. What its user may do there is asked of AccessControl, as anywhere.
 */
final class WidgetTokenHandler implements AccessTokenHandlerInterface
{
    /** The request attribute holding the WidgetToken that authenticated it. */
    private const string ATTRIBUTE = '_widget_token';

    public function __construct(
        private readonly WidgetTokenRepository $tokens,
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The token that authenticated the request, null outside the widget.
     */
    public static function tokenOf(?Request $request): ?WidgetToken
    {
        $token = $request?->attributes->get(self::ATTRIBUTE);

        return $token instanceof WidgetToken ? $token : null;
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // Nothing that is not shaped like a token of ours reaches the database.
        if (1 !== preg_match(WidgetToken::PATTERN, $accessToken)) {
            throw new BadCredentialsException('Invalid credentials.');
        }
        $request = $this->requests->getMainRequest();
        $token = $this->tokens->findOneBySecret($accessToken);
        if (null === $request || null === $token || $token->isExpired($this->clock->now())) {
            throw new BadCredentialsException('Invalid credentials.');
        }
        $project = $token->getSite()->getProject();
        if ($project->getKey() !== $request->attributes->get('projectKey') || $project->getWorkspace()->getKey() !== $request->attributes->get('workspaceKey')) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        $request->attributes->set(self::ATTRIBUTE, $token);
        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
