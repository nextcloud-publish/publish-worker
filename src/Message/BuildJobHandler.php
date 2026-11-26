<?php

declare(strict_types=1);

namespace App\Message;

use App\Job\ArchiveExtractor;
use App\Job\ContentDownloader;
use App\Job\JobWorkspace;
use App\Job\SiteRenderer;
use App\Job\StaticSiteStatusStore;
use App\Job\StatusNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Builds a job received from publish API via symfony messenger:
 *  download, extract, render, publish, save, report.
 * A delete builds nothing: it removes the published site, then the static_site entry, then reports `deleted`.
 *
 * A build that fails exits this method by throwing, not by returning an
 * error, and which exception type is thrown decides what Messenger does
 * next:
 *  - \InvalidArgumentException: e.g. an archive with no pages, invalid render options or a callback that rejects us.
 *    Stops retry via an UnrecoverableMessageHandlingException so the transport reports it instead of retrying the build.
 *  - \RuntimeException: the environment might differ next time (disk,
 *    network, a half-finished swap). Retry via Messenger redelivery.
 *
 * BuildFailureHandler handles a failed build if retries are exhausted --
 * Successfully built sites are handled here.
 *
 * @param ContentDownloader $contentDownloader The content downloader to use to download the content.
 * @param ArchiveExtractor $archiveExtractor The archive extractor to use to extract the content.
 * @param SiteRenderer $siteRenderer The site renderer to use to render the content.
 * @param JobWorkspace $jobWorkspace The job workspace to use to manage the job workspace.
 * @param StatusNotifier $statusNotifier The status notifier to use to notify the status of the build.
 * @param StaticSiteStatusStore $statusStore Saves the result in publish's static_site table.
 * @param LoggerInterface $logger The app logger.
 *
 * Registered as a message handler in services.yaml rather than via
 * #[AsMessageHandler], which pins it to the "builds" transport.
 */
final class BuildJobHandler
{
    public function __construct(
        private readonly ContentDownloader $contentDownloader,
        private readonly ArchiveExtractor $archiveExtractor,
        private readonly SiteRenderer $siteRenderer,
        private readonly JobWorkspace $jobWorkspace,
        private readonly StatusNotifier $statusNotifier,
        private readonly StaticSiteStatusStore $statusStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BuildJob $buildJob): void
    {
        $startedAt = hrtime(true);
        $this->logger->info('Build started', ['url' => $buildJob->content_download_url]);

        // Every operation runs inside the same try block, so a failure in any step is retried or
        // handled as a failure by Messenger.
        try {
            match ($buildJob->operation) {
                BuildOperation::Create, BuildOperation::Update => $this->publishBuild($buildJob, $startedAt),
                BuildOperation::Delete => $this->delete($buildJob),
            };
        } catch (\InvalidArgumentException $e) {
            // Marked unrecoverable so the retry strategy is skipped entirely.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Builds and publishes a create or an update, saves its status and reports it to the callback.
     *
     * @param BuildJob $buildJob The create or update to run.
     * @param int $startedAt hrtime() when the worker took the job, for the logged duration.
     * @return void
     * @throws \InvalidArgumentException if $buildJob has no build_id, or the content, the render options or the callback answer is invalid
     * @throws \RuntimeException if a step fails in a way the next attempt may not
     */
    private function publishBuild(BuildJob $buildJob, int $startedAt): void
    {
        $buildId = $buildJob->build_id ?? throw new \InvalidArgumentException(sprintf('A %s job needs build_id.', $buildJob->operation->value));

        $pageCount = $this->build($buildJob, $buildId);
        $this->jobWorkspace->publish($buildId, $buildJob->slug);
        $this->jobWorkspace->clear($buildId);

        $publishUrl = $this->jobWorkspace->publishUrl($buildJob->slug);

        // Before the callback, so the log records the publish even if the callback fails.
        $this->logger->info('Published site', [
            'pages' => $pageCount,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            'url' => $publishUrl,
        ]);

        // Before the callback too, so the database holds the result even if the callback fails;
        // a database error is retried like any other environment error.
        if (!$this->statusStore->markPublished($buildJob->static_site_id, $buildId)) {
            $this->logger->warning('static_site status not saved', ['reason' => 'no static_site entry for this build']);
        }

        $this->statusNotifier->notifyPublished(
            callbackStatusUrl: $buildJob->callback_status_url,
            publishUrl: $publishUrl,
        );
    }

    /**
     * Removes a published site, then its static_site entry, and reports the site deleted to the callback.
     * The entry stays while the files are still served: a failed removal is retried.
     *
     * @param BuildJob $buildJob The delete to run.
     * @return void
     * @throws \InvalidArgumentException if the callback rejects the report
     * @throws \RuntimeException if the published site cannot be removed, or the database or the callback fails in a way the next attempt may not
     */
    private function delete(BuildJob $buildJob): void
    {
        if (!$this->jobWorkspace->unpublish($buildJob->slug)) {
            throw new \RuntimeException(sprintf('Could not remove the published site of %s', $buildJob->slug));
        }

        // A database error is retried like any other environment error.
        if (!$this->statusStore->deleteSite($buildJob->static_site_id)) {
            $this->logger->warning('static_site entry not deleted', ['reason' => 'no static_site entry for this site']);
        }

        $this->logger->info('Deleted site', ['url' => $this->jobWorkspace->publishUrl($buildJob->slug)]);

        $this->statusNotifier->notifyDeleted($buildJob->callback_status_url);
    }

    /**
     * Downloads, extracts and renders the site: everything before it goes live.
     *
     * @param BuildJob $buildJob The create or update to build.
     * @param string $buildId The build_id of $buildJob.
     * @return int number of rendered pages
     * @throws \InvalidArgumentException if $buildJob has no content_download_url or no title
     */
    private function build(BuildJob $buildJob, string $buildId): int
    {
        if ($buildJob->content_download_url === null || $buildJob->title === null) {
            throw new \InvalidArgumentException(sprintf('A %s job needs content_download_url and title.', $buildJob->operation->value));
        }

        /** Clear any previous attempt to avoid any mess when mixing up builds. */
        $buildJobDir = $this->jobWorkspace->reset($buildId);
        $this->logger->debug('Prepared job directory', ['dir' => $buildJobDir]);

        $buildJobInputDir = $this->jobWorkspace->buildJobInputDir($buildId);
        $archiveFile = $this->contentDownloader->download($buildJob->content_download_url, $buildJobInputDir);
        $this->logger->debug('Downloaded content archive', ['file' => $archiveFile, 'bytes' => (int) @filesize($archiveFile)]);

        $buildJobUnarchivedDir = $this->jobWorkspace->buildJobUnarchivedDir($buildId);
        $this->archiveExtractor->extract($archiveFile, $buildJobUnarchivedDir);
        $this->logger->debug('Extracted content archive', ['dir' => $buildJobUnarchivedDir]);

        $buildJobOutputDir = $this->jobWorkspace->buildJobOutputDir($buildId);
        $pageCount = $this->siteRenderer->render($buildJobUnarchivedDir, $buildJobOutputDir, $buildJob->title, $buildJob->render_options);
        $this->logger->debug('Rendered site', ['pages' => $pageCount, 'dir' => $buildJobOutputDir]);

        return $pageCount;
    }
}
