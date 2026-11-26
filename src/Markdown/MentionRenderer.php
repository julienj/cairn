<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

final class MentionRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (!$node instanceof Mention && !$node instanceof TeamMention) {
            throw new \InvalidArgumentException('Incompatible node type: '.$node::class);
        }

        return new HtmlElement('span', ['class' => 'mention'], '@'.htmlspecialchars($node->name, \ENT_QUOTES | \ENT_HTML5));
    }
}
