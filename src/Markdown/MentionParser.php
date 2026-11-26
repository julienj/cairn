<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Reads `@[Name](user:12)` and `@[Name](team:3)`, the forms the editor
 * writes for the mention of a person and of a team.
 */
final class MentionParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('@\[([^\]\n]+)\]\((user|team):(\d+)\)');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        [$name, $kind, $id] = $inlineContext->getSubMatches();
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild('team' === $kind ? new TeamMention((int) $id, $name) : new Mention((int) $id, $name));

        return true;
    }
}
