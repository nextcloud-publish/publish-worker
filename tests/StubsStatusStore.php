<?php

declare(strict_types=1);

namespace App\Tests;

use App\Job\StaticSiteStatusStore;
use Doctrine\DBAL\Connection;

/**
 * A real StaticSiteStatusStore on a stubbed connection, the way MockHttpClient stands in for the
 * network: the handler tests see every statement without a database. StaticSiteStatusStoreTest covers
 * the SQL against Postgres.
 */
trait StubsStatusStore
{
    /** @var list<array<mixed>> every statement the store ran: its SQL verb (UPDATE, DELETE), then static_site_id and build_id */
    private array $statements = [];

    /** @var ?\Closure(): int the entries a statement matches, or throws as the database would; null for 1 */
    private ?\Closure $statementResult = null;

    private function statusStore(): StaticSiteStatusStore
    {
        $connection = self::createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            $this->statements[] = [strtok($sql, ' '), ...$params];

            return $this->statementResult !== null ? ($this->statementResult)() : 1;
        });

        return new StaticSiteStatusStore($connection);
    }
}
