<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedDatabase;
use Tests\Support\OfflineHttp;
use Tests\Support\PassportKeys;

abstract class TestCase extends BaseTestCase
{
    protected bool $requiresMySql = false;

    protected function setUpTraits()
    {
        // Runs before DatabaseMigrations/RefreshDatabase can modify any table.
        IsolatedDatabase::assertAllowed(DB::connection()->getDriverName(), DB::connection()->getDatabaseName(), $this->requiresMySql);
        OfflineHttp::configure();
        PassportKeys::configure();

        return parent::setUpTraits();
    }
}
