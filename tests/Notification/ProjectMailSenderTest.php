<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\Comment;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\MailGrouping;
use App\Entity\Tracker;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\UnfollowIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\Message\Notification\NotifyIssueChange;
use App\MessageHandler\Notification\NotifyIssueChangeHandler;
use App\Notification\ProjectMailSender;
use App\Repository\PendingNotificationRepository;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The mails that leave, and when: commands run, the worker queues what
 * they left on the in-memory transport, the clock moves and the sender is
 * asked as the Scheduler would. Mails are sent on the spot in the test
 * environment (SendEmailMessage routed sync, to the null mailer), so
 * their body can be read. Thomas (manager) and Claire (author) follow
 * every issue of the fixtures; Nadia develops.
 */
final class ProjectMailSenderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use KernelHelpers;
    use MailerAssertionsTrait;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private InMemoryTransport $async;
    private ProjectMailSender $sender;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->clock = self::mockTime('2026-01-05 10:00:00');
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
        $this->sender = self::getContainer()->get(ProjectMailSender::class);
    }

    public function testNothingLeavesBeforeThingsCalmedDown(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();

        self::assertSame(0, $this->sender->sendDue());
        $this->clock->sleep(ProjectMailSender::CALM - 1);
        self::assertSame(0, $this->sender->sendDue());
        self::assertEmailCount(0);

        $this->clock->sleep(1);
        self::assertSame(1, $this->sender->sendDue());

        self::assertEmailCount(1, message: 'the author does not receive their own creation');
        $email = $this->emailTo('thomas@agency.test');
        self::assertEmailHeaderSame($email, 'subject', \sprintf('[Maison Dupont / Online shop] %s Empty CSV export', $issue->getKey()));
        self::assertEmailHtmlBodyContains($email, 'created the issue');
        self::assertEmailHtmlBodyContains($email, 'The downloaded file is empty.', 'the description comes with the creation');
        self::assertEmailHtmlBodyContains($email, \sprintf('http://localhost/MD/SPT/issues/%d', $issue->getNumber()));
        self::assertSame([], $this->waiting(), 'what was sent no longer waits');
        self::assertSame(0, $this->sender->sendDue());
    }

    public function testAnActionAndACommentAreOneMail(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        // The worker queues each change as it comes: whoever follows later is not told what came before.
        $this->queue();
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start', comment: 'I am on it.'));
        $this->queue();
        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), 'Fixed on **staging**.'));
        $this->queue();
        $this->clock->sleep(ProjectMailSender::CALM);

        self::assertSame(2, $this->sender->sendDue());

        self::assertEmailCount(2);
        $toClaire = $this->emailTo('claire@client.test');
        self::assertEmailHtmlBodyContains($toClaire, 'applied &quot;Start&quot;');
        self::assertEmailHtmlBodyContains($toClaire, 'I am on it.');
        self::assertEmailHtmlBodyContains($toClaire, '<strong>staging</strong>');
        self::assertEmailHtmlBodyNotContains($toClaire, 'created the issue', 'her own creation is not told to her');
        self::assertEmailHtmlBodyNotContains($toClaire, 'The downloaded file is empty.', 'no description without the creation');
        $toThomas = $this->emailTo('thomas@agency.test');
        self::assertEmailHtmlBodyContains($toThomas, 'created the issue');
        self::assertEmailHtmlBodyContains($toThomas, 'I am on it.');

        // Each comment links to its address on the page, the second too though its author is named once.
        $comments = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findBy(['issue' => $issue], ['id' => 'ASC']);
        self::assertCount(2, $comments);
        foreach ($comments as $comment) {
            self::assertEmailHtmlBodyContains($toClaire, \sprintf('href="http://localhost/MD/SPT/issues/%d#comment-%d"', $issue->getNumber(), $comment->getId()));
        }
    }

    public function testTheIssuesOfAProjectShareAMailAndTwoProjectsDoNot(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $intranetBugs = new Tracker($this->fixtures->access->intranet, 'Bug', DefinitionFactory::workflowRow('bug', $entityManager));
        $entityManager->persist($intranetBugs);
        $entityManager->flush();

        $first = $this->fixtures->createIssue($this->fixtures->client);
        $second = $this->fixtures->createIssue($this->fixtures->client, title: 'Blurry logo');
        $elsewhere = $this->fixtures->createIssue($this->fixtures->client, $intranetBugs, title: 'Broken search');
        $this->queue();
        $this->clock->sleep(ProjectMailSender::CALM);

        self::assertSame(2, $this->sender->sendDue());

        $subjects = array_map(static fn (Email $email): ?string => $email->getSubject(), $this->emails());
        sort($subjects);
        self::assertSame([
            \sprintf('[Maison Dupont / Intranet] %s Broken search', $elsewhere->getKey()),
            \sprintf('[Maison Dupont / Online shop] %s, %s', $first->getKey(), $second->getKey()),
        ], $subjects);
    }

    public function testWhoeverChoseAMailPerIssueGetsOneForEach(): void
    {
        $this->fixtures->manager->groupMails(MailGrouping::Issue);
        $first = $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();
        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $first->getKey(), 'It happens every morning.'));
        $this->queue();
        $this->clock->sleep(ProjectMailSender::CALM - 60);
        $second = $this->fixtures->createIssue($this->fixtures->client, title: 'Blurry logo');
        $this->queue();

        $this->clock->sleep(60);
        self::assertSame(1, $this->sender->sendDue(), 'the first issue calmed down, the second still waits');
        $email = $this->emailTo('thomas@agency.test');
        self::assertEmailHeaderSame($email, 'subject', \sprintf('[Maison Dupont / Online shop] %s Empty CSV export', $first->getKey()));
        self::assertEmailHtmlBodyContains($email, 'It happens every morning.', 'what happened to one issue still shares its mail');
        self::assertEmailHtmlBodyNotContains($email, 'Blurry logo');

        $this->clock->sleep(ProjectMailSender::CALM - 60);
        self::assertSame(1, $this->sender->sendDue());
        $subjects = array_map(static fn (Email $email): ?string => $email->getSubject(), $this->emails());
        self::assertContains(\sprintf('[Maison Dupont / Online shop] %s Blurry logo', $second->getKey()), $subjects);
    }

    public function testWhoeverChoseNoMailGetsNoneAndNothingWaitsForThem(): void
    {
        $this->fixtures->manager->groupMails(MailGrouping::None);
        $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();
        self::assertSame([], $this->waiting(), 'nothing was queued for Thomas');
        $this->clock->sleep(ProjectMailSender::CALM);

        self::assertSame(0, $this->sender->sendDue());
        self::assertEmailCount(0);
    }

    public function testWhatWaitedIsLeftOutOnceNoMailIsChosen(): void
    {
        $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();
        $manager = (int) $this->fixtures->manager->getId();
        $this->commands->dispatch(new UpdateUser($manager, mailGrouping: MailGrouping::None, actorId: $manager));
        // The sender reads the stored choice, as a worker would.
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->clock->sleep(ProjectMailSender::CALM);
        self::assertNotSame([], $this->waiting());
        self::assertSame(0, $this->sender->sendDue());
        self::assertEmailCount(0);
        self::assertSame([], $this->waiting(), 'what waited is gone without a mail');
    }

    public function testAProjectThatNeverCalmsDownLeavesAfterTheLongestWait(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();
        $step = ProjectMailSender::CALM - 60;
        for ($waited = $step; $waited < ProjectMailSender::MAX_WAIT; $waited += $step) {
            $this->clock->sleep($step);
            $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'One more thing.'));
            $this->queue();
            self::assertSame(0, $this->sender->sendDue(), \sprintf('nothing after %d seconds', $waited));
        }

        $this->clock->sleep(ProjectMailSender::MAX_WAIT - ($waited - $step));

        self::assertSame(1, $this->sender->sendDue());
        self::assertEmailHtmlBodyContains($this->emailTo('thomas@agency.test'), 'One more thing.');
    }

    public function testABlockingIssueLeavesSooner(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), criticality: Criticality::Blocking));
        $this->queue();

        $this->clock->sleep(ProjectMailSender::URGENT_CALM - 1);
        self::assertSame(0, $this->sender->sendDue());
        $this->clock->sleep(1);
        self::assertSame(2, $this->sender->sendDue(), 'the creation waiting for Thomas and the change waiting for Claire');

        self::assertEmailHtmlBodyContains($this->emailTo('thomas@agency.test'), 'Blocking');
        self::assertEmailHtmlBodyContains($this->emailTo('claire@client.test'), 'Blocking');
    }

    public function testAnIssueNoLongerFollowedIsLeftOut(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();
        $this->commands->dispatch(new UnfollowIssue((int) $this->fixtures->manager->getId(), $issue->getKey()));
        $this->clock->sleep(ProjectMailSender::CALM);

        self::assertSame(0, $this->sender->sendDue());

        self::assertEmailCount(0);
        self::assertSame([], $this->waiting());
    }

    public function testAMailThatCannotBeBuiltDoesNotHoldTheOthersBack(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();
        $this->commands->dispatch(new AddComment((int) $this->fixtures->manager->getId(), $issue->getKey(), 'Looking into it.'));
        $this->queue();
        // Thomas waits for the creation, Claire for the comment: his address stops being one.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE app_user SET email = :email WHERE id = :id', ['email' => 'not an address', 'id' => $this->fixtures->manager->getId()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(1, $this->sender->sendDue(force: true));

        self::assertEmailHtmlBodyContains($this->emailTo('claire@client.test'), 'Looking into it.');
        self::assertCount(1, $this->waiting(), 'what could not be sent keeps waiting');
    }

    public function testAChangeThatWaitedForMoreThanADayIsDroppedWithoutAMail(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();
        // The worker was down: the creation is a day old when a comment comes.
        $this->clock->sleep(ProjectMailSender::MAX_AGE + 1);
        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'Any news?'));
        $this->queue();

        self::assertSame(1, $this->sender->sendDue(force: true));

        $email = $this->emailTo('thomas@agency.test');
        self::assertEmailHtmlBodyContains($email, 'Any news?');
        self::assertEmailHtmlBodyNotContains($email, 'created the issue');
        self::assertSame([], $this->waiting());
    }

    public function testAReferenceToAnInvisibleIssueStaysTextForAnExternalReader(): void
    {
        $task = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks, title: 'Internal task');
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();
        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), \sprintf('See #%s.', $task->getKey())));
        $this->queue();

        self::assertSame(2, $this->sender->sendDue(force: true));

        $link = \sprintf('href="http://localhost/MD/SPT/issues/%d"', $task->getNumber());
        self::assertEmailHtmlBodyContains($this->emailTo('claire@client.test'), '#'.$task->getKey());
        self::assertEmailHtmlBodyNotContains($this->emailTo('claire@client.test'), $link);
        self::assertEmailHtmlBodyContains($this->emailTo('thomas@agency.test'), $link);
    }

    /** What the worker does: every change the commands left is queued for its subscribers. */
    public function testWhatARuleDidIsToldUnderItsName(): void
    {
        $this->commands->dispatch(new CreateRule((int) $this->fixtures->access->shop->getId(), 'Welcome', null, [
            'trigger' => ['type' => 'issue_created'],
            'actions' => [['type' => 'comment', 'params' => ['body' => 'Thank you, we **received** your issue.']], ['type' => 'set_criticality', 'params' => ['criticality' => 'high']]],
        ]));
        $this->fixtures->createIssue($this->fixtures->client);
        $this->queue();

        $this->clock->sleep(ProjectMailSender::CALM + 1);
        $this->sender->sendDue();

        $toClaire = $this->emailTo('claire@client.test');
        self::assertEmailHtmlBodyContains($toClaire, 'Welcome', 'the author of the issue is told what the rule did, under its name');
        self::assertEmailHtmlBodyContains($toClaire, 'we <strong>received</strong> your issue');
        self::assertEmailHtmlBodyContains($toClaire, 'automation');
        self::assertEmailHtmlBodyNotContains($toClaire, 'created the issue', 'not what they did themselves');
        self::assertEmailHtmlBodyContains($this->emailTo('thomas@agency.test'), 'Welcome');
    }

    private function queue(): void
    {
        $handler = self::getContainer()->get(NotifyIssueChangeHandler::class);
        foreach ($this->async->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof NotifyIssueChange) {
                $handler($message);
            }
        }
        $this->async->reset();
    }

    /**
     * @return list<object>
     */
    private function waiting(): array
    {
        return self::getContainer()->get(PendingNotificationRepository::class)->findWaiting();
    }

    /**
     * @return list<Email>
     */
    private function emails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn (object $message): bool => $message instanceof Email));
    }

    private function emailTo(string $address): Email
    {
        foreach ($this->emails() as $email) {
            if (\in_array($address, array_map(static fn ($to): string => $to->getAddress(), $email->getTo()), true)) {
                return $email;
            }
        }
        self::fail(\sprintf('No mail to %s.', $address));
    }
}
