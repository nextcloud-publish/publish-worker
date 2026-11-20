<?php

declare(strict_types=1);

namespace App\Message;

use App\Job\JobWorkspace;
use App\Job\StaticSiteStatusStore;
use App\Job\StatusNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Handles the failure of a build by clearing the job workspace, removing whatever a failed create left behind (its published site and
 * its static_site entry) and then notifying the status callback, so the client can POST the site again without a conflict.
 * A failed update keeps the published site and marks the entry `failed`.
 * Via the getSubscribedEvents method it subscribes to the WorkerMessageFailedEvent to handle build failures with the onMessageFailed method.
 * Setting a low priority ensures that the message is handled after the SendFailedMessageForRetryListener has had a chance to set the retry flag.
 * Before sending the notification the error message is redacted to strip out any sensitive information.
 *
 * @param JobWorkspace $jobWorkspace The job workspace helper class.
 * @param StatusNotifier $statusNotifier The notifier to use to notify the status callback.
 * @param StaticSiteStatusStore $statusStore Deletes or marks failed the build's entry in publish's static_site table.
 * @param LoggerInterface $logger The app logger.
 * @param string $buildsDir The build temp directory.
 * @param string $pagesDir The published directory.
 */
final class BuildFailureHandler implements EventSubscriberInterface
{
    /** Caps a pathological error message from bloating the callback body. */
    private const MAX_ERROR_LENGTH = 500;

    private const TRUNCATION_MARKER = ' ...(truncated)';

    /** @var array<string, string> root => placeholder, longest root first */
    private readonly array $roots;

    /** Same env vars as JobWorkspace; passed directly since redact() matches on plain strings. */
    public function __construct(
        private readonly JobWorkspace $jobWorkspace,
        private readonly StatusNotifier $statusNotifier,
        private readonly StaticSiteStatusStore $statusStore,
        private readonly LoggerInterface $logger,
        string $buildsDir,
        string $pagesDir,
    ) {
        $roots = [
            rtrim($buildsDir, '/') => '<build>',
            rtrim($pagesDir, '/') => '<published>',
        ];

        // Longest first, so a root nested inside another is replaced by its own
        // name rather than by its parent's.
        uksort($roots, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $this->roots = $roots;
    }

    /**
     * Subscribes to the WorkerMessageFailedEvent to handle build failures with the onMessageFailed method.
     * Setting a low priority ensures that the message is handled after the SendFailedMessageForRetryListener has had a chance to set the retry flag.
     *
     * @return array<string, array{string, int}> the method to call and its priority, per event
     */
    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', 0]];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $buildJob = $event->getEnvelope()->getMessage();

        if (!$buildJob instanceof BuildJob) {
            return;
        }

        $error = $event->getThrowable();

        // Raw, with paths: this is the operator's record.
        $this->logger->error('Build failed', ['exception' => $error]);

        $this->jobWorkspace->clear($buildJob->build_id);

        switch ($buildJob->operation) {
            case BuildOperation::Create:
                $this->removeFailedCreate($buildJob);
                break;
            case BuildOperation::Update:
                $this->markFailedUpdate($buildJob);
                break;
        }

        try {
            $this->statusNotifier->notifyFailed(
                callbackStatusUrl: $buildJob->callback_status_url,
                errorMessage: $this->redact($error->getMessage()),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Could not report failed build', ['exception' => $e]);
        }
    }

    /**
     * Removes what a failed create left behind: its published site and its static_site entry.
     * Both go before the callback, so the client's next POST of the site finds no entry in its way.
     * The files go first: if the database then fails, nothing unowned stays published.
     *
     * @param BuildJob $buildJob The create that failed.
     * @return void
     */
    private function removeFailedCreate(BuildJob $buildJob): void
    {
        $this->jobWorkspace->unpublish($buildJob->slug);

        try {
            if (!$this->statusStore->deleteSite($buildJob->static_site_id, $buildJob->build_id)) {
                $this->logger->warning('static_site entry not deleted', ['reason' => 'no static_site entry for this build']);
            }
        } catch (\Throwable $e) {
            // The entry stays, and the client's next POST of the site gets a 409 until it is removed.
            $this->logger->error('Could not delete static_site entry in the database', ['exception' => $e]);
        }
    }

    /**
     * Marks the entry of a failed update `failed`; the site published before the update stays.
     *
     * @param BuildJob $buildJob The update that failed.
     * @return void
     */
    private function markFailedUpdate(BuildJob $buildJob): void
    {
        try {
            if (!$this->statusStore->markFailed($buildJob->static_site_id, $buildJob->build_id)) {
                $this->logger->warning('static_site status not saved', ['reason' => 'no static_site entry for this build']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Could not mark the static_site entry failed', ['exception' => $e]);
        }
    }

    /** Strips the deployment's filesystem layout out of a failure message.
     *
     * @param string $errorMessage The error message to redact.
     * @return string The redacted error message.
     */
    private function redact(string $errorMessage): string
    {
        // 1. Flatten control chars: tar output can contain them, and a raw NUL breaks JSON.
        $errorMessage = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $errorMessage);

        // 2. Name known roots before the generic collapse below, so the message
        //    still says which tree failed.
        foreach ($this->roots as $root => $placeholder) {
            if ($root !== '') {
                $errorMessage = str_replace($root, $placeholder, $errorMessage);
            }
        }

        // 3. Strip URL userinfo before that collapse, so a credential embedded
        //    in the (client-supplied) download URL can't survive.
        $errorMessage = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/@\s]*@#i', '$1', $errorMessage);

        // 4. Collapse remaining absolute paths. The lookbehind excludes: \w
        //    (leaves https://host/a/b alone), : and / (the scheme's own "//"),
        //    and > (text after a <build>/<published> placeholder).
        $errorMessage = (string) preg_replace('#(?<![\w:/>])/(?:[\w.+@%-]+/)*[\w.+@%-]+#', '<path>', $errorMessage);

        $errorMessage = trim($errorMessage);

        if (mb_strlen($errorMessage) > self::MAX_ERROR_LENGTH) {
            return mb_substr($errorMessage, 0, self::MAX_ERROR_LENGTH) . self::TRUNCATION_MARKER;
        }

        return $errorMessage;
    }
}
