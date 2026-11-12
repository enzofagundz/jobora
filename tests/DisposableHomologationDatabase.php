<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class DisposableHomologationDatabase
{
    public static function assertSafe(): void
    {
        $database = (string) config('database.connections.sqlite.database');
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || filled(config('database.connections.sqlite.url'))
            || dirname($database) !== '/tmp/opencode'
            || realpath(dirname($database)) !== '/tmp/opencode'
            || preg_match('/\Ajobora38(?:-[a-z0-9]+)*-testing\.sqlite\z/', basename($database)) !== 1
            || ! is_file($database) || is_link($database) || realpath($database) !== $database) {
            throw new RuntimeException('Homologação exige SQLite descartável canônico; RefreshDatabase bloqueado.');
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== $database
            || $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $connection->selectOne('PRAGMA database_list')->file !== $database) {
            throw new RuntimeException('SQLite efetivo difere do arquivo descartável reservado; RefreshDatabase bloqueado.');
        }
    }
}
