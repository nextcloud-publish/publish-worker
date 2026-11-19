<?php

declare(strict_types=1);

namespace App\Job;

use Doctrine\DBAL\Connection;

/**
 * Writes a build's outcome to publish's static_site table: the only SQL the worker runs.
 * publish owns the table and creates the entry as `pending` with the build_id it enqueued.
 * A published build sets the status; a failed one deletes the entry, so the client can POST the
 * site again without a conflict.
 *
 * Each statement is fenced on build_id, so a build publish has since replaced, or whose site was
 * deleted, changes nothing: the methods then return false. Repeating one is harmless.
 *
 * @param Connection $connection The connection to publish's database.
 */
final class StaticSiteStatusStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Sets the status to `published`, publish's App\Entity\StaticSiteStatus::Published.
     *
     * @throws \Doctrine\DBAL\Exception if the database cannot be reached or rejects the update
     */
    public function markPublished(string $staticSiteId, string $buildId): bool
    {
        return $this->connection->executeStatement(
            "UPDATE static_site SET status = 'published' WHERE id = ? AND build_id = ?",
            [$staticSiteId, $buildId],
        ) === 1;
    }

    /** @throws \Doctrine\DBAL\Exception if the database cannot be reached or rejects the delete */
    public function deleteSite(string $staticSiteId, string $buildId): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM static_site WHERE id = ? AND build_id = ?',
            [$staticSiteId, $buildId],
        ) === 1;
    }
}
