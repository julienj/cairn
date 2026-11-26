<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * `@[Name](team:3)`. As parsed, the name is the one written; the renderer
 * replaces the node with one carrying the team's current name.
 */
final class TeamMention extends AbstractInline
{
    public function __construct(
        public readonly int $teamId,
        public readonly string $name,
    ) {
        parent::__construct();
    }
}
