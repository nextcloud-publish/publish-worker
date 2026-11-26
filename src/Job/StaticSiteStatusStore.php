<?php

declare(strict_types=1);

namespace App\Job;

use Doctrine\DBAL\Connection;

/**
 * Writes a build's outcome to publish's static_site table: the only SQL the worker runs.
 * publish owns the table and creates the entry as `pending` with the build_id it enqueued.
 * A published build sets the status to `published`, a failed update or delete sets it to `failed`.
 * A delete and a failed create delete the entry, so the client can POST the site again without a conflict.
 *
 * The status updates of a build are fenced on its build_id, so a build publish has since replaced, or whose
 * site was deleted, changes nothing: the methods then return false. A delete has no build_id and goes by the
 * site's id alone. Repeating any statement is harmless.
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

    /**
     * Sets the status to `failed`, publish's App\Entity\StaticSiteStatus::Failed.
     * Fenced on $buildId when it is set; a delete passes null.
     *
     * @throws \Doctrine\DBAL\Exception if the database cannot be reached or rejects the update
     */
    public function markFailed(string $staticSiteId, ?string $buildId): bool
    {
        if ($buildId === null) {
            return $this->connection->executeStatement(
                "UPDATE static_site SET status = 'failed' WHERE id = ?",
                [$staticSiteId],
            ) === 1;
        }

        return $this->connection->executeStatement(
            "UPDATE static_site SET status = 'failed' WHERE id = ? AND build_id = ?",
            [$staticSiteId, $buildId],
        ) === 1;
    }

    /** @throws \Doctrine\DBAL\Exception if the database cannot be reached or rejects the delete */
    public function deleteSite(string $staticSiteId): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM static_site WHERE id = ?',
            [$staticSiteId],
        ) === 1;
    }
}
