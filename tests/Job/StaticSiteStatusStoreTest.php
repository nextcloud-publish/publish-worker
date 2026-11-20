<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\StaticSiteStatusStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Covers App\Job\StaticSiteStatusStore against a real Postgres, in publish_worker_test.
 * The table comes from tests/fixtures/static_site.sql, the copy of publish's migration, so this
 * fails when the worker's SQL no longer fits publish's schema.
 */
#[Group('database')]
final class StaticSiteStatusStoreTest extends KernelTestCase
{
    private const SITE_ID = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';
    private const BUILD_ID = '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b';
    private const OTHER_BUILD_ID = '0199a1b2-3c4d-7e5f-8a9b-dddddddddddd';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->executeStatement('DROP TABLE IF EXISTS static_site');
        $this->connection->executeStatement((string) file_get_contents(__DIR__ . '/../fixtures/static_site.sql'));

        // As publish stores a new site: pending, with the build it enqueued.
        $this->connection->insert('static_site', [
            'id' => self::SITE_ID,
            'slug' => 'some_collective',
            'status' => 'pending',
            'build_id' => self::BUILD_ID,
            'updated_at' => '2026-11-21 12:00:00+00',
        ]);
    }

    private function storedStatus(): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM static_site WHERE id = ?', [self::SITE_ID]);
    }

    private function store(): StaticSiteStatusStore
    {
        return new StaticSiteStatusStore($this->connection);
    }

    public function testMarksTheSitePublished(): void
    {
        self::assertTrue($this->store()->markPublished(self::SITE_ID, self::BUILD_ID));
        self::assertSame('published', $this->storedStatus());
    }

    /** A delete and a failed create leave no entry, so the client can POST the site again. */
    public function testDeletesTheEntryByTheSiteIdAlone(): void
    {
        self::assertTrue($this->store()->deleteSite(self::SITE_ID));
        self::assertFalse($this->storedStatus(), 'no entry left');
    }

    /** A failed update keeps its published site, so the entry stays and says so. */
    public function testMarksTheSiteFailed(): void
    {
        self::assertTrue($this->store()->markFailed(self::SITE_ID, self::BUILD_ID));
        self::assertSame('failed', $this->storedStatus());
    }

    /** A delete has no build_id, so its failure goes by the site id alone. */
    public function testMarksTheSiteFailedWithoutABuildId(): void
    {
        self::assertTrue($this->store()->markFailed(self::SITE_ID, null));
        self::assertSame('failed', $this->storedStatus());
    }

    /** A retried build publishes again and sets the same status again. */
    public function testMarkingTwiceIsHarmless(): void
    {
        self::assertTrue($this->store()->markPublished(self::SITE_ID, self::BUILD_ID));
        self::assertTrue($this->store()->markPublished(self::SITE_ID, self::BUILD_ID));
        self::assertSame('published', $this->storedStatus());
    }

    /** An older build that finishes late must not overwrite the result of the build publish enqueued since. */
    public function testAnotherBuildLeavesTheStatusAlone(): void
    {
        self::assertFalse($this->store()->markPublished(self::SITE_ID, self::OTHER_BUILD_ID));
        self::assertFalse($this->store()->markFailed(self::SITE_ID, self::OTHER_BUILD_ID));
        self::assertSame('pending', $this->storedStatus());
    }

    /** The site may have been deleted while its build ran. */
    public function testAnUnknownSiteChangesNothing(): void
    {
        self::assertFalse($this->store()->markPublished('4b6c1e2a-9d3f-4a7b-8c5e-2f1d0a9b8c7d', self::BUILD_ID));
        self::assertFalse($this->store()->markFailed('4b6c1e2a-9d3f-4a7b-8c5e-2f1d0a9b8c7d', self::BUILD_ID));
        self::assertFalse($this->store()->deleteSite('4b6c1e2a-9d3f-4a7b-8c5e-2f1d0a9b8c7d'));
        self::assertSame('pending', $this->storedStatus());
    }
}
