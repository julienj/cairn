<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * Something the reporter did on the site before reporting, as the report
 * kept it: what was acted on, never what was typed nor chosen.
 */
final readonly class SiteAction
{
    public function __construct(
        /** `click`, `change` (a field was changed) or `submit` (a form was sent). */
        public string $kind,
        /** What was acted on: `button`, `link`, `textbox`, `checkbox`…, or the name of its element. */
        public ?string $role,
        /** The name the page shows for it: the text of a button or a link, the label of a field. */
        public ?string $name,
        /** Where it stands in the page, as a short CSS selector. */
        public ?string $selector,
        /** The address of the page it was done on, without its parameters. */
        public ?string $page,
        /** How many times in a row. */
        public int $count,
        public string $at,
    ) {
    }
}
