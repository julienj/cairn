<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Factory\MembershipFactory;
use App\Factory\ProjectFactory;
use App\Factory\TrackerFactory;
use App\Factory\UserFactory;
use App\Message\Query\Issue\SearchIssues;
use App\Repository\IssueRepository;
use App\Security\AccessControl;
use App\Story\AppStory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SQL of SearchIssuesHandler must agree with AccessControl::canSee()
 * for every user and every issue: a disagreement is a data leak or a
 * missing issue.
 */
final class SearchIssuesVisibilityTest extends KernelTestCase
{
    use KernelHelpers;

    public function testSqlAndPhpAgreeOnTheWholeDevelopmentDataset(): void
    {
        self::bootKernel();
        AppStory::load();
        $access = self::getContainer()->get(AccessControl::class);
        $issues = self::getContainer()->get(IssueRepository::class)->findAll();
        $users = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findAll();
        self::assertCount(12, $users);

        $partialViews = 0;
        foreach ($users as $user) {
            $expected = array_map(static fn (Issue $issue): ?int => $issue->getId(), array_values(array_filter($issues, static fn (Issue $issue): bool => $access->canSee($user, $issue))));
            sort($expected);

            self::assertSame($expected, $this->visibleIds($user), $user->getEmail());
            if ([] !== $expected && \count($expected) < \count($issues)) {
                ++$partialViews;
            }
        }
        self::assertGreaterThanOrEqual(4, $partialViews, 'the dataset must exercise partial visibility, not only all or nothing');
    }

    public function testTargetedRules(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $builder = new IssueBuilder(self::getContainer()->get(EntityManagerInterface::class));

        $project = ProjectFactory::createOne();
        $sibling = ProjectFactory::createOne(['workspace' => $project->getWorkspace()]);
        $elsewhere = ProjectFactory::createOne();
        $tracker = TrackerFactory::createOne(['project' => $project]);
        $privateTracker = TrackerFactory::createOne(['project' => $project, 'private' => true]);
        $siblingTracker = TrackerFactory::createOne(['project' => $sibling]);
        $elsewhereTracker = TrackerFactory::createOne(['project' => $elsewhere]);
        $author = UserFactory::new()->internal()->create();

        $public = $builder->issue($tracker, $author, 'Public');
        $privateIssue = $builder->issue($tracker, $author, 'Private', private: true);
        $inPrivateTracker = $builder->issue($privateTracker, $author, 'Private tracker');
        $inSibling = $builder->issue($siblingTracker, $author, 'Sibling project');
        $inElsewhere = $builder->issue($elsewhereTracker, $author, 'Elsewhere');

        $stranger = UserFactory::createOne();
        self::assertSame([], $this->visibleIds($stranger), 'no membership');

        $workspaceClient = UserFactory::createOne();
        MembershipFactory::createOne(['user' => $workspaceClient, 'workspace' => $project->getWorkspace(), 'role' => 'client']);
        self::assertSame(self::ids($public, $inSibling), $this->visibleIds($workspaceClient), 'through the workspace, private ones excluded');

        $projectClient = UserFactory::createOne();
        MembershipFactory::createOne(['user' => $projectClient, 'project' => $project, 'role' => 'client']);
        self::assertSame(self::ids($public), $this->visibleIds($projectClient), 'direct on one project only');

        $everything = self::ids($public, $privateIssue, $inPrivateTracker, $inSibling, $inElsewhere);
        self::assertSame($everything, $this->visibleIds(UserFactory::new()->internal()->create()), 'internal users see everything');
        self::assertSame($everything, $this->visibleIds(UserFactory::new()->admin()->create()), 'administrators too');
    }

    /**
     * Every issue the viewer may see, across all pages.
     *
     * @return list<int>
     */
    private function visibleIds(User $viewer): array
    {
        $ids = [];
        $page = 1;
        do {
            $result = self::queryBus()->ask(new SearchIssues($viewer, closed: true, page: $page));
            foreach ($result->items as $issue) {
                $ids[] = (int) $issue->getId();
            }
        } while (++$page <= $result->pageCount());
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function ids(Issue ...$issues): array
    {
        $ids = array_map(static fn (Issue $issue): int => (int) $issue->getId(), $issues);
        sort($ids);

        return $ids;
    }
}
