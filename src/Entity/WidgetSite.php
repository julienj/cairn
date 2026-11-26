<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WidgetSiteRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A site the widget of a project may be shown on: its origin
 * (`https://staging.example.com`), which is who may frame the pages of
 * the widget and what a widget token is bound to. The widget is optional
 * without a setting: a project without any site has none. A site is
 * current state: removing it removes the tokens that were given for it.
 */
#[ORM\Entity(repositoryClass: WidgetSiteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_widget_site_project_origin', columns: ['project_id', 'origin'])]
class WidgetSite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column(length: 255)]
    private string $origin;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string $origin an origin as normalise() gives it
     */
    public function __construct(Project $project, string $origin)
    {
        $this->project = $project;
        $this->origin = $origin;
        $this->createdAt = now();
    }

    /**
     * The origin of an address of the web, as browsers write it: scheme,
     * host and the port when it is not the one of the scheme, in lower
     * case, an accented domain in its ASCII form. Null for anything else, an address with a path included: a
     * site is declared, not a page.
     */
    public static function normalise(string $address): ?string
    {
        $parts = parse_url(trim($address));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !\in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }
        // as the browsers name it: an accented domain in its ASCII form (xn--…), which is what they send
        $host = idn_to_ascii($parts['host']) ?: strtolower($parts['host']);
        if (1 !== preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if (null === $port || ('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port)) {
            return $scheme.'://'.$host;
        }

        return \sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    /**
     * Whether the browsers allow a screenshot there: over HTTPS, or on
     * the machine of the developer, by its name or its loopback address.
     */
    public function isSecure(): bool
    {
        $host = (string) parse_url($this->origin, \PHP_URL_HOST);

        return str_starts_with($this->origin, 'https://')
            || 'localhost' === $host
            || str_ends_with($host, '.localhost')
            || 1 === preg_match('/^127(\.\d{1,3}){3}$/', $host);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
