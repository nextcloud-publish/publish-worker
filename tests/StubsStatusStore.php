<?php

declare(strict_types=1);

namespace App\Tests;

use App\Job\StaticSiteStatusStore;
use Doctrine\DBAL\Connection;

/**
 * A real StaticSiteStatusStore on a stubbed connection, the way MockHttpClient stands in for the
 * network: the handler tests see every UPDATE without a database. StaticSiteStatusStoreTest covers
 * the SQL against Postgres.
 */
trait StubsStatusStore
{
    /** @var list<array<mixed>> the parameters of every UPDATE: status, static_site_id, build_id */
    private array $statusUpdates = [];

    /** @var ?\Closure(): int the entries an UPDATE matches, or throws as the database would; null for 1 */
    private ?\Closure $statusUpdateResult = null;

    private function statusStore(): StaticSiteStatusStore
    {
        $connection = self::createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            $this->statusUpdates[] = $params;

            return $this->statusUpdateResult !== null ? ($this->statusUpdateResult)() : 1;
        });

        return new StaticSiteStatusStore($connection);
    }
}
