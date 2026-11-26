<?php

declare(strict_types=1);

namespace App\Notification;

use App\Config\ConfigRegistry;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\IssueSubscription;
use App\Entity\IssueTeamSubscription;
use App\Entity\Team;
use App\Entity\User;
use App\Event\CommentAdded;
use App\Event\CommentEdited;
use App\Event\IssueCreated;
use App\Event\IssueTransitioned;
use App\Event\IssueUpdated;
use App\Markdown\MarkdownRenderer;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListProjectTeams;
use App\Message\QueryBus;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Who follows an issue, and why. The rules listen to the domain events
 * inside the transaction: the author at creation, with the members whose
 * role on the project follows new issues (`followsNewIssues` in the
 * configuration), the assignee when assigned, the author of a comment and
 * the people a text mentions. A team the issue is given to, or a text
 * mentions, follows it as one (IssueTeamSubscription), and keeps following it afterwards, as
 * a person does. Nobody is subscribed to what they cannot
 * see: a private issue for an external user, a mention in a private
 * comment. Unsubscribing deletes the row, and the next of these actions
 * subscribes again: whoever takes part follows the conversation.
 */
final class SubscriptionRules
{
    public function __construct(
        private readonly IssueSubscriptionRepository $subscriptions,
        private readonly IssueTeamSubscriptionRepository $teamSubscriptions,
        private readonly QueryBus $queries,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly MarkdownRenderer $markdown,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener]
    public function onIssueCreated(IssueCreated $event): void
    {
        $issue = $event->issue;
        $this->subscribe($issue, $issue->getAuthor());
        if (null !== $issue->getAssignee()) {
            $this->subscribe($issue, $issue->getAssignee());
        }
        if (null !== $issue->getAssigneeTeam()) {
            $this->subscribeTeam($issue, $issue->getAssigneeTeam());
        }
        foreach ($this->followers($issue) as $follower) {
            $this->subscribe($issue, $follower);
        }
        $this->subscribeMentioned($issue, $issue->getDescription());
    }

    #[AsEventListener]
    public function onIssueUpdated(IssueUpdated $event): void
    {
        if (null !== $event->newAssignee) {
            $this->subscribe($event->issue, $event->newAssignee);
        }
        if (null !== $event->newAssigneeTeam) {
            $this->subscribeTeam($event->issue, $event->newAssigneeTeam);
        }
        if ($event->descriptionChanged) {
            $this->subscribeMentioned($event->issue, $event->issue->getDescription());
        }
    }

    #[AsEventListener]
    public function onIssueTransitioned(IssueTransitioned $event): void
    {
        if (null !== $event->comment) {
            $this->onComment($event->comment);
        }
    }

    #[AsEventListener]
    public function onCommentAdded(CommentAdded $event): void
    {
        $this->onComment($event->comment);
    }

    #[AsEventListener]
    public function onCommentEdited(CommentEdited $event): void
    {
        $this->onComment($event->comment);
    }

    /**
     * Subscribes the user to the issue, unless they already follow it,
     * are deactivated or may not see it.
     *
     * @return bool whether a subscription was made
     */
    public function subscribe(Issue $issue, User $user): bool
    {
        if (!$user->isActive() || !$this->access->canSee($user, $issue) || $this->isSubscribed($issue, $user)) {
            return false;
        }
        $this->entityManager->persist(new IssueSubscription($issue, $user));

        return true;
    }

    /**
     * Subscribes the team to the issue, unless it follows it already or,
     * external, would follow a private issue: as an external person, who
     * is never subscribed to what they cannot see. A team is subscribed
     * as one; what each of its members may read is decided again when a
     * mail is built, for whoever is part of it then.
     *
     * @return bool whether a subscription was made
     */
    public function subscribeTeam(Issue $issue, Team $team): bool
    {
        if ((!$team->isInternal() && $issue->isPrivate()) || $this->followsAsTeam($issue, $team)) {
            return false;
        }
        $this->entityManager->persist(new IssueTeamSubscription($issue, $team));

        return true;
    }

    public function followsAsTeam(Issue $issue, Team $team): bool
    {
        if (null !== $issue->getId() && null !== $this->teamSubscriptions->findOne($issue, $team)) {
            return true;
        }
        // Made earlier in the same command, not flushed yet.
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof IssueTeamSubscription && $entity->getIssue() === $issue && $entity->getTeam() === $team) {
                return true;
            }
        }

        return false;
    }

    public function unsubscribeTeam(Issue $issue, Team $team): void
    {
        $subscription = $this->teamSubscriptions->findOne($issue, $team);
        if (null !== $subscription) {
            $this->entityManager->remove($subscription);
        }
    }

    public function unsubscribe(Issue $issue, User $user): void
    {
        $subscription = $this->subscriptions->findOne($issue, $user);
        if (null !== $subscription) {
            $this->entityManager->remove($subscription);
        }
    }

    public function isSubscribed(Issue $issue, User $user): bool
    {
        if (null !== $issue->getId() && null !== $this->subscriptions->findOne($issue, $user)) {
            return true;
        }
        // Made earlier in the same command, not flushed yet.
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof IssueSubscription && $entity->getIssue() === $issue && $entity->getUser() === $user) {
                return true;
            }
        }

        return false;
    }

    /**
     * The members of the project, directly, through its workspace or
     * through a team, whose effective role follows new issues.
     *
     * @return list<User>
     */
    public function followers(Issue $issue): array
    {
        $project = $issue->getProject();
        $definition = $this->config->definition();
        $followers = [];
        foreach ($this->queries->ask(new ListProjectMembers($project)) as $user) {
            $role = $this->access->roleOn($user, $project);
            if (null !== $role && $definition->role($role)->followsNewIssues) {
                $followers[] = $user;
            }
        }

        return $followers;
    }

    private function onComment(Comment $comment): void
    {
        if (null !== $comment->getAuthor()) {
            $this->subscribe($comment->getIssue(), $comment->getAuthor());
        }
        $this->subscribeMentioned($comment->getIssue(), $comment->getBody(), $comment);
    }

    /**
     * The teams the text mentions, and the people it mentions if they may read it: the comment when
     * it came with one, the issue otherwise.
     */
    private function subscribeMentioned(Issue $issue, string $text, ?Comment $comment = null): void
    {
        $teamIds = $this->markdown->mentionedTeamIds($text);
        if ([] !== $teamIds) {
            // Only a team with a role on the project is mentioned, as the editor offers; it then follows the issue as one.
            foreach ($this->queries->ask(new ListProjectTeams($issue->getProject())) as $team) {
                // A private comment is for internal users: mentioning an external team there subscribes nobody.
                if (\in_array($team->getId(), $teamIds, true) && ($team->isInternal() || true !== $comment?->isPrivate())) {
                    $this->subscribeTeam($issue, $team);
                }
            }
        }
        $ids = $this->markdown->mentionedUserIds($text);
        if ([] === $ids) {
            return;
        }
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            if (null === $comment || $this->access->canSeeComment($user, $comment)) {
                $this->subscribe($issue, $user);
            }
        }
    }
}
