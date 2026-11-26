<?php

declare(strict_types=1);

namespace App\Api\Mapper;

use App\Api\Resource\IssueResource;
use App\Api\Resource\Value\IssueField;
use App\Api\Resource\Value\NamedRef;
use App\Api\Resource\Value\OfferedTransition;
use App\Api\Resource\Value\PlaceRef;
use App\Api\Resource\Value\SiteReport;
use App\Api\Resource\Value\TagRef;
use App\Config\ConfigRegistry;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Tag;
use App\Message\Query\Issue\IssueDetail;
use App\Repository\IssueFieldValueRepository;
use App\Repository\IssueReportRepository;
use App\Repository\IssueTagRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use App\Workflow\WorkflowProvider;

/**
 * What the API shows of an issue to the viewer of the request: what
 * depends on them (the internal fields, the actions offered and where
 * they lead) is decided with the rules of the pages, never assumed.
 */
final class IssueMapper
{
    public function __construct(
        private readonly Refs $refs,
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly WorkflowProvider $workflows,
        private readonly IssueFieldValueRepository $held,
        private readonly IssueTagRepository $issueTags,
        private readonly IssueReportRepository $reports,
    ) {
    }

    /**
     * The issues of a page as a list shows them: without their
     * description, their tags read at once.
     *
     * @param list<Issue> $issues
     *
     * @return list<IssueResource>
     */
    public function summaries(array $issues): array
    {
        $tags = $this->issueTags->tagsByIssue($issues);

        return array_map(fn (Issue $issue): IssueResource => $this->map($issue, $tags[(int) $issue->getId()] ?? []), $issues);
    }

    /**
     * An issue as its page shows it: its description, its fields, its own files and the
     * actions the viewer may apply from its place.
     */
    public function detail(IssueDetail $detail): IssueResource
    {
        $viewer = $this->viewer->get();
        $issue = $detail->issue;
        $workflow = $this->workflows->definition($issue->getTracker()->getWorkflowId());
        $raw = $this->held->valuesOf($issue);

        $fields = [];
        foreach ($this->config->definition()->fieldValues($workflow, $raw, $viewer->isInternal()) as $fieldValue) {
            $fields[] = new IssueField($fieldValue->field->id, $fieldValue->field->label, $raw[$fieldValue->field->id], $fieldValue->value);
        }
        $transitions = [];
        foreach ($this->access->transitionsFor($viewer, $issue) as $transition) {
            $transitions[] = new OfferedTransition(
                $transition->id,
                $transition->label,
                $transition->description,
                $transition->requiresComment,
                new PlaceRef($transition->to, $this->workflows->place($transition->to)->label),
            );
        }

        $report = $this->reports->reportOf($issue);

        return $this->map($issue, $this->issueTags->tagsOf($issue), $issue->getDescription(), $fields, array_map($this->refs->file(...), $detail->issueAttachments()), $transitions, null === $report ? null : SiteReport::of($report));
    }

    /**
     * @param list<Tag>                                  $tags
     * @param list<IssueField>|null                      $fields
     * @param list<\App\Api\Resource\Value\FileRef>|null $attachments
     * @param list<OfferedTransition>|null               $transitions
     */
    private function map(Issue $issue, array $tags, ?string $description = null, ?array $fields = null, ?array $attachments = null, ?array $transitions = null, ?SiteReport $report = null): IssueResource
    {
        $release = $issue->getRelease();

        $closed = PlaceStatus::Closed === $this->workflows->placeOf($issue)->status;

        return new IssueResource(
            key: $issue->getKey(),
            title: $issue->getTitle(),
            description: $description,
            project: $issue->getProject()->getIssuePrefix(),
            tracker: new NamedRef((int) $issue->getTracker()->getId(), $issue->getTracker()->getName()),
            place: new PlaceRef($issue->getPlace(), $this->workflows->placeOf($issue)->label),
            closed: $closed,
            criticality: $issue->getCriticality()->value,
            private: $issue->isPrivate(),
            author: $this->refs->user($issue->getAuthor()),
            assignee: null === $issue->getAssignee() ? null : $this->refs->user($issue->getAssignee()),
            assigneeTeam: null === $issue->getAssigneeTeam() ? null : new NamedRef((int) $issue->getAssigneeTeam()->getId(), $issue->getAssigneeTeam()->getName()),
            release: null === $release ? null : new NamedRef((int) $release->getId(), $release->getName()),
            tags: array_map(TagRef::of(...), $tags),
            createdAt: $issue->getCreatedAt(),
            updatedAt: $issue->getUpdatedAt(),
            // The date of the last move to a closed step: said only while the issue is closed.
            closedAt: $closed ? $issue->getClosedAt() : null,
            url: $this->refs->issueUrl($issue),
            importedRef: $issue->getImportedRef(),
            fields: $fields,
            attachments: $attachments,
            transitions: $transitions,
            technicalReport: $report,
        );
    }
}
