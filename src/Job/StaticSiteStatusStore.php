<?php

declare(strict_types=1);

namespace App\Job;

use Doctrine\DBAL\Connection;

/**
 * Sets a site's status in publish's static_site table: the only SQL the worker runs.
 * publish owns the table and creates the entry as `pending` with the build_id it enqueued.
 *
 * Each update is fenced on build_id, so a build publish has since replaced, or whose site was
 * deleted, changes nothing: the methods then return false. A retried build sets the same status
 * again, which is harmless.
 *
 * @param Connection $connection The connection to publish's database.
 */
final class StaticSiteStatusStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** @throws \Doctrine\DBAL\Exception if the database cannot be reached or rejects the update */
    public function markPublished(string $staticSiteId, string $buildId): bool
    {
        return $this->setStatus('published', $staticSiteId, $buildId);
    }

    /** @throws \Doctrine\DBAL\Exception if the database cannot be reached or rejects the update */
    public function markFailed(string $staticSiteId, string $buildId): bool
    {
        return $this->setStatus('failed', $staticSiteId, $buildId);
    }

    /** The values are publish's App\Entity\StaticSiteStatus. */
    private function setStatus(string $status, string $staticSiteId, string $buildId): bool
    {
        return $this->connection->executeStatement(
            'UPDATE static_site SET status = ? WHERE id = ? AND build_id = ?',
            [$status, $staticSiteId, $buildId],
        ) === 1;
    }
}
