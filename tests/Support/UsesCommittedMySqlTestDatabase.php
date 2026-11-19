<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;

trait UsesCommittedMySqlTestDatabase
{
    use DatabaseTruncation;

    private bool $mySqlTestDatabaseVerified = false;

    protected function beforeTruncatingDatabase(): void
    {
        OverlappingCardAttempts::assertTestDatabase();
        $this->mySqlTestDatabaseVerified = true;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->mySqlTestDatabaseVerified) {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }
}
