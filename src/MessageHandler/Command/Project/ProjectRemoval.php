<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Project;

use App\Entity\AutomationRule;
use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\CodeRepository;
use App\Entity\Enum\ImportStatus;
use App\Entity\Import;
use App\Entity\ImportRecord;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\Tag;
use App\Entity\TeamAccess;
use App\Entity\Tracker;
use App\Entity\WidgetSite;
use App\Forge\ForgeApiException;
use App\Forge\ForgeApiLocator;
use App\Message\Attachment\RemoveFiles;
use App\Repository\CodeRepositoryRepository;
use App\Repository\ImportRepository;
use App\Repository\IssueEraser;
use App\Repository\ListViewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Removes a project and all it holds, for DeleteProject and for
 * DeleteWorkspace, which removes each of its projects: a few bulk
 * statements in the transaction of the command, whatever the size of the
 * project, in the order the foreign keys allow. The files (attachments,
 * the archives of its finished imports, what an import extracted) go
 * once that transaction committed (RemoveFiles).
 *
 * The webhooks of its repositories are removed from their forge first,
 * as far as the forge answers: a refusal is logged and stops nothing,
 * the hook is then left to be removed there by hand.
 */
final class ProjectRemoval
{
    /** What belongs to a project beside its issues, in the order the foreign keys allow (the widget tokens go with their site). */
    private const array BELONGINGS = [AutomationRule::class, Release::class, Tag::class, Budget::class, Tracker::class, Import::class, CodeRepository::class, WidgetSite::class, Membership::class, TeamAccess::class];

    public function __construct(
        private readonly IssueEraser $eraser,
        private readonly ImportRepository $imports,
        private readonly CodeRepositoryRepository $repositories,
        private readonly ListViewRepository $views,
        private readonly ForgeApiLocator $apis,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ProjectException when the project is not archived, or an import is running on it
     */
    public function remove(Project $project): void
    {
        if (!$project->isReadOnly()) {
            throw ProjectException::notArchived($project->getName());
        }
        $imports = $this->imports->findBy(['project' => $project]);
        foreach ($imports as $import) {
            if ($import->getStatus()->isInProgress()) {
                throw ProjectException::importRunning($project->getName());
            }
        }
        $this->uninstallHooks($project);

        $scope = ['project' => $project->getId()];
        $attachments = $this->eraser->eraseIssuesOf($project);
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s r WHERE r.import IN (SELECT i.id FROM %s i WHERE i.project = :project)', ImportRecord::class, Import::class))->execute($scope);
        // the budget lines that named an issue went with the issues; the others go with their budget
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s l WHERE l.budget IN (SELECT b.id FROM %s b WHERE b.project = :project)', BudgetLine::class, Budget::class))->execute($scope);
        foreach (self::BELONGINGS as $class) {
            $this->entityManager->createQuery(\sprintf('DELETE FROM %s b WHERE b.project = :project', $class))->execute($scope);
        }
        $this->views->forget(\sprintf('/%s/%s', $project->getWorkspace()->getKey(), $project->getKey()), below: true);
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s p WHERE p.id = :project', Project::class))->execute($scope);

        // the rows went without the unit of work knowing: what it still holds of them must not be written back
        $archives = [];
        $importIds = [];
        foreach ($imports as $import) {
            $importIds[] = (int) $import->getId();
            if (ImportStatus::Completed === $import->getStatus()) {
                // only a finished import put its archive away; one still waiting may be imported elsewhere
                $archives[] = $import->getFile();
            }
            $this->entityManager->detach($import);
        }
        $this->entityManager->detach($project);

        if ([] !== $attachments || [] !== $importIds) {
            $this->bus->dispatch(new RemoveFiles($attachments, $archives, $importIds), [new DispatchAfterCurrentBusStamp()]);
        }
    }

    private function uninstallHooks(Project $project): void
    {
        foreach ($this->repositories->findByProject($project) as $repository) {
            if (null === $hookId = $repository->getHookId()) {
                continue;
            }
            try {
                $this->apis->for($repository->getForge())->uninstallHook($repository->getPath(), $hookId);
            } catch (ForgeApiException $e) {
                $this->logger->warning('The webhook of {path} could not be removed from its forge while deleting project {project}: remove it there by hand.', ['path' => $repository->getPath(), 'project' => $project->getIssuePrefix(), 'exception' => $e]);
            }
            $this->entityManager->detach($repository);
        }
    }
}
