<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshDatabaseSafe
{
    use RefreshDatabase {
        migrateFreshUsing as refreshDatabaseMigrateFreshUsing;
    }

    protected function beforeRefreshingDatabase()
    {
        $name = (string) config('database.default');
        $driver = (string) config("database.connections.{$name}.driver");
        $database = (string) config("database.connections.{$name}.database");

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(
                "RefreshDatabase bloqué : {$driver} {$database} (attendu sqlite :memory:)."
            );
        }
    }

    protected function migrateFreshUsing()
    {
        return array_merge($this->refreshDatabaseMigrateFreshUsing(), [
            '--force' => true,
        ]);
    }
}
