<?php

namespace Tests\Support;

final class IsolatedDatabase
{
    public static function assertAllowed(string $driver, string $database, bool $mysqlOnly = false): void
    {
        if ($driver === 'mysql' && $database === 'prex_audit_test') {
            return;
        }
        if (! $mysqlOnly && $driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        throw new \RuntimeException('Tests require SQLite :memory: or isolated MySQL prex_audit_test; Integration requires MySQL.');
    }
}
