<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use App\Api\Resource\Value\FileRef;
use App\Api\Resource\Value\IssueField;
use App\Api\Resource\Value\NamedRef;
use App\Api\Resource\Value\OfferedTransition;
use App\Api\Resource\Value\PlaceRef;
use App\Api\Resource\Value\SiteReport;
use App\Api\Resource\Value\TagRef;
use App\Api\Resource\Value\UserRef;
use App\Api\State\ApplyTransitionProcessor;
use App\Api\State\ChangeTrackerProcessor;
use App\Api\State\CreateIssueProcessor;
use App\Api\State\IssueCollectionProvider;
use App\Api\State\IssueProvider;
use App\Api\State\MoveIssueProcessor;
use App\Api\State\UpdateIssueProcessor;

/**
 * An issue, named by its key (`MD-SPT-12`). A list gives its properties;
 * the issue itself adds its description, its fields, its files, the
 * actions the token's user may apply from its place and, when it was
 * reported through the widget, what the site noted.
 */
#[ApiResource(
    shortName: 'Issue',
    operations: [
        new GetCollection(
            uriTemplate: '/issues',
            description: 'The issues the user may see, filtered and sorted like the lists of the application.',
            paginationItemsPerPage: 50,
            paginationMaximumItemsPerPage: 200,
            paginationClientItemsPerPage: true,
            provider: IssueCollectionProvider::class,
            parameters: [
                'project' => new QueryParameter(schema: ['type' => 'string'], description: 'Key of a project, `MD-SPT`.'),
                'tracker[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'integer']], description: 'Ids of categories.'),
                'place[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string']], description: 'Ids of steps, `in_progress`. A step is the same in every category that uses it: add `tracker[]` to keep to the issues of some categories.'),
                'criticality[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'blocking']]]),
                'assignee[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string']], description: 'Ids of users, `me`, or `none` for the unassigned issues.'),
                'author' => new QueryParameter(schema: ['type' => 'string'], description: 'Id of a user, or `me`.'),
                'release[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string']], description: 'Ids of releases, or `none` for the issues outside any.'),
                'q' => new QueryParameter(schema: ['type' => 'string'], description: 'Words of the title or the description, or a key.'),
                'closed' => new QueryParameter(schema: ['type' => 'boolean'], description: 'Also the finished issues, left out by default.'),
                'sort' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['key', 'title', 'project', 'tracker', 'place', 'criticality', 'assignee', 'updated']]),
                'order' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['asc', 'desc']]),
            ],
        ),
        new Get(uriTemplate: '/issues/{key}', provider: IssueProvider::class),
        new Post(uriTemplate: '/issues', input: CreateIssueInput::class, processor: CreateIssueProcessor::class),
        new Patch(
            uriTemplate: '/issues/{key}',
            description: 'Changes the properties sent, each needing its own right; the others keep their value.',
            input: UpdateIssueInput::class,
            provider: IssueProvider::class,
            processor: UpdateIssueProcessor::class,
        ),
        new Post(
            uriTemplate: '/issues/{key}/transitions',
            status: 200,
            description: 'Applies one of the actions the issue offers (`transitions`), with a comment when it asks for one.',
            input: TransitionInput::class,
            read: false,
            processor: ApplyTransitionProcessor::class,
            name: 'issue_transition',
        ),
        new Put(
            uriTemplate: '/issues/{key}/step',
            description: 'Takes the issue to a step of its workflow: any step when the role of the token\'s user moves issues freely, otherwise a step an action of theirs leads to. A role that moves freely is offered no action (`transitions` is empty); the others name the action or send a comment with it through `transitions`.',
            input: StepInput::class,
            read: false,
            processor: MoveIssueProcessor::class,
            name: 'issue_step',
        ),
        new Put(
            uriTemplate: '/issues/{key}/tracker',
            description: 'Moves the issue to another tracker of its project, on a step of the workflow of that tracker.',
            input: TrackerInput::class,
            read: false,
            processor: ChangeTrackerProcessor::class,
            name: 'issue_tracker',
        ),
    ],
)]
final readonly class IssueResource
{
    /**
     * @param list<TagRef>                 $tags
     * @param list<IssueField>|null        $fields      on the issue itself only
     * @param list<FileRef>|null           $attachments on the issue itself only: its own files, those of a comment come with it
     * @param list<OfferedTransition>|null $transitions on the issue itself only
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $key,
        public string $title,
        /** Markdown. On the issue itself only: a list leaves it out. */
        public ?string $description,
        /** Key of the project, `MD-SPT`. */
        public string $project,
        public NamedRef $tracker,
        public PlaceRef $place,
        public bool $closed,
        public string $criticality,
        public bool $private,
        public UserRef $author,
        public ?UserRef $assignee,
        public ?NamedRef $release,
        /** The tags of its project it carries, by name. */
        public array $tags,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $closedAt,
        /** The page of the issue in the application. */
        public string $url,
        /** What named the issue in the tracker it was imported from, which `q` finds too. */
        public ?string $importedRef = null,
        public ?array $fields = null,
        public ?array $attachments = null,
        public ?array $transitions = null,
        /**
         * On the issue itself only, when it was reported through the widget: what the site it was
         * reported from noted (the page, the browser, the errors of the console, the failed Ajax
         * requests, the pages seen). It is what a site said, to read as facts about the problem.
         */
        public ?SiteReport $technicalReport = null,
    ) {
    }
}
