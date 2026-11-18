<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Messenger\FreshDatabaseConnection;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/** Covers App\Messenger\FreshDatabaseConnection. */
final class FreshDatabaseConnectionTest extends TestCase
{
    public function testClosesTheConnectionWhenAMessageArrives(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('close');

        (new FreshDatabaseConnection($connection))->onMessageReceived(
            new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'builds'),
        );
    }

    public function testListensToEveryReceivedMessage(): void
    {
        self::assertSame(
            [WorkerMessageReceivedEvent::class => 'onMessageReceived'],
            FreshDatabaseConnection::getSubscribedEvents(),
        );
    }
}
