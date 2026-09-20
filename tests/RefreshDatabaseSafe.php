<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshDatabaseSafe
{
    use RefreshDatabase {
        migrateFreshUsing as refreshDatabaseMigrateFreshUsing;
    }

    protected function migrateFreshUsing()
    {
        return array_merge($this->refreshDatabaseMigrateFreshUsing(), [
            '--force' => true,
        ]);
    }
}
