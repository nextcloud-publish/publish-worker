<?php

declare(strict_types=1);

namespace App\Messenger;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Closes the database connection whenever the worker receives a message, so the build starts
 * without one and DBAL connects anew on its first query.
 *
 * A consumer runs for up to an hour (--time-limit=3600). A connection opened for an earlier build
 * may have died since, through a Postgres restart or a dropped network connection, and DBAL does
 * not reconnect by itself: every build after it would fail until the process restarts.
 * Messenger's doctrine_ping_connection middleware does this job, but it needs the ORM, which the
 * worker does not have.
 *
 * @param Connection $connection The connection to publish's database.
 */
final class FreshDatabaseConnection implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, string> the method to call, per event
     */
    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageReceivedEvent::class => 'onMessageReceived'];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        // A no-op when no connection is open.
        $this->connection->close();
    }
}
