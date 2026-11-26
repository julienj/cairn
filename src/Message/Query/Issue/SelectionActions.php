<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\Tracker;
use App\Entity\User;

/**
 * The answer to ListSelectionActions: the selected issues the viewer
 * sees, and what they may change on all of them at once. A right counts
 * only when it holds on every project of the selection.
 */
final readonly class SelectionActions
{
    /**
     * @param list<Issue>           $issues
     * @param Project|null          $project       the project of the issues, when the selection holds one
     * @param list<User>            $assignees     members of every project of the selection
     * @param list<Team>            $assigneeTeams the teams that hold a role on every project of the selection
     * @param list<Release>         $releases      the open releases of the project, when the selection holds one project
     * @param list<Tag>             $tags          the tags of the project the viewer may put on the issues, when the selection holds one project
     * @param list<Tracker>         $moveTrackers  the trackers every issue may be moved to, for who moves freely,
     *                                             when the selection holds one project
     * @param list<PlaceDefinition> $places        the places every issue may be taken to in its own tracker: those the workflows
     *                                             of all of them use, in the order of the instance, with the same right
     */
    public function __construct(
        public array $issues,
        public ?Project $project = null,
        public bool $canAssign = false,
        public array $assignees = [],
        public bool $canComment = false,
        public bool $canCommentPrivately = false,
        public bool $canSetCriticality = false,
        public bool $canSetPrivate = false,
        public array $releases = [],
        public array $tags = [],
        public array $moveTrackers = [],
        public array $places = [],
        public array $assigneeTeams = [],
    ) {
    }

    /**
     * Whether the selection spans several projects, which the page then
     * names on each line.
     */
    public function isAcrossProjects(): bool
    {
        return [] !== $this->issues && null === $this->project;
    }
}
