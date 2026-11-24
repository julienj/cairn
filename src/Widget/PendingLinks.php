<?php

declare(strict_types=1);

namespace App\Widget;

use Psr\Cache\CacheItemPoolInterface;

/**
 * The agreements waiting for their widget: who linked their account, to
 * the widget of which project, on which site, kept a few minutes under
 * the challenge the widget chose (the SHA-256 of a secret it alone
 * knows). They live in the `cache.app` pool, which is in the database:
 * the page that agrees and the request that redeems may be served by two
 * containers. An agreement is taken once.
 */
final class PendingLinks
{
    /** How long the widget has to come and take the agreement, in seconds. */
    private const int LIFETIME = 300;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function leave(string $challenge, int $userId, int $projectId, string $origin): void
    {
        $item = $this->cache->getItem(self::key($challenge));
        $item->set(['user' => $userId, 'project' => $projectId, 'origin' => $origin]);
        $item->expiresAfter(self::LIFETIME);
        $this->cache->save($item);
    }

    /**
     * The agreement left under the challenge of that secret, removed.
     *
     * @return array{user: int, project: int, origin: string}|null
     */
    public function take(#[\SensitiveParameter] string $verifier): ?array
    {
        $key = self::key(hash('sha256', $verifier));
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }
        $this->cache->deleteItem($key);
        $link = $item->get();
        if (!\is_array($link) || !\is_int($link['user'] ?? null) || !\is_int($link['project'] ?? null) || !\is_string($link['origin'] ?? null)) {
            return null;
        }

        return ['user' => $link['user'], 'project' => $link['project'], 'origin' => $link['origin']];
    }

    private static function key(string $challenge): string
    {
        return 'widget_link.'.hash('sha256', $challenge);
    }
}
