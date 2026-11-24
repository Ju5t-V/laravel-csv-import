<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase pinned to in-memory SQLite, because the Docker env sets
 * DB_DATABASE to the dev database file and phpunit.xml does not override it.
 */
trait RefreshMemoryDatabase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
        ]);
    }
}
