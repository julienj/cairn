<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

/**
 * A command on an issue cannot be carried out as asked. Authorization
 * failures use Symfony's AccessDeniedException instead.
 */
final class IssueException extends \DomainException
{
    public static function notFound(string $what, int|string $id): self
    {
        return new self(\sprintf('%s "%s" not found.', $what, $id));
    }

    public static function archivedProject(string $projectName): self
    {
        return new self(\sprintf('Project "%s" is archived.', $projectName));
    }

    public static function trackerOutsideProject(string $trackerName, string $projectName): self
    {
        return new self(\sprintf('Tracker "%s" does not belong to project "%s".', $trackerName, $projectName));
    }

    public static function archivedTracker(string $trackerName): self
    {
        return new self(\sprintf('Tracker "%s" is archived: it takes no new issue.', $trackerName));
    }

    public static function unknownField(string $fieldId, string $workflowId): self
    {
        return new self(\sprintf('Field "%s" is not used by workflow "%s".', $fieldId, $workflowId));
    }

    public static function notAWebAddress(string $fieldId): self
    {
        return new self(\sprintf('Field "%s" takes a web address: http:// or https://.', $fieldId));
    }

    public static function missingField(string $fieldId): self
    {
        return new self(\sprintf('Field "%s" is required.', $fieldId));
    }

    public static function unknownTransition(string $transitionId, string $workflowId): self
    {
        return new self(\sprintf('Transition "%s" does not exist in workflow "%s".', $transitionId, $workflowId));
    }

    public static function transitionUnavailable(string $transitionId, string $placeId): self
    {
        return new self(\sprintf('Transition "%s" cannot be applied from place "%s".', $transitionId, $placeId));
    }

    public static function commentRequired(string $transitionId): self
    {
        return new self(\sprintf('Transition "%s" requires a comment.', $transitionId));
    }

    public static function unknownPlace(string $placeId, string $workflowId): self
    {
        return new self(\sprintf('Place "%s" does not exist in workflow "%s".', $placeId, $workflowId));
    }

    public static function notAMember(string $userName, string $projectName): self
    {
        return new self(\sprintf('%s holds no role on project "%s".', $userName, $projectName));
    }

    public static function teamWithoutAccess(string $teamName, string $projectName): self
    {
        return new self(\sprintf('The team "%s" holds no role on project "%s".', $teamName, $projectName));
    }

    public static function deactivatedUser(string $userName): self
    {
        return new self(\sprintf('%s is deactivated.', $userName));
    }

    public static function privateTracker(string $trackerName): self
    {
        return new self(\sprintf('Issues of tracker "%s" are private.', $trackerName));
    }

    public static function samePlace(string $placeLabel): self
    {
        return new self(\sprintf('The issue is already on "%s".', $placeLabel));
    }

    public static function notInColumn(string $issueKey, string $neighbourKey): self
    {
        return new self(\sprintf('Issue "%s" is not in the kanban column of "%s".', $neighbourKey, $issueKey));
    }

    public static function sameTracker(string $trackerName): self
    {
        return new self(\sprintf('The issue is already in tracker "%s".', $trackerName));
    }

    public static function releaseOutsideProject(string $releaseName, string $projectName): self
    {
        return new self(\sprintf('Release "%s" does not belong to project "%s".', $releaseName, $projectName));
    }

    public static function closedRelease(string $releaseName): self
    {
        return new self(\sprintf('Release "%s" is no longer open: it takes no new issue.', $releaseName));
    }
}
