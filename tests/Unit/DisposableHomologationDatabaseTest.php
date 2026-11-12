<?php

use Illuminate\Support\Facades\DB;
use Tests\DisposableHomologationDatabase;

it('rejects unsafe homologation targets without touching their contents', function (string $kind) {
    $path = '/tmp/opencode/jobora38-'.bin2hex(random_bytes(6)).'-testing.sqlite';
    file_put_contents($path, 'must survive');
    $link = '/tmp/opencode/jobora38-'.bin2hex(random_bytes(6)).'-testing.sqlite';
    $target = match ($kind) {
        'traversal' => '/tmp/opencode/../opencode/'.basename($path),
        'symlink' => $link,
        'missing' => $link,
        'unreserved' => '/tmp/opencode/unreserved.sqlite',
    };
    if ($kind === 'symlink') {
        symlink($path, $link);
    }
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $target, 'database.connections.sqlite.url' => null]);
    try {
        expect(fn () => DisposableHomologationDatabase::assertSafe())->toThrow(RuntimeException::class, 'canônico')
            ->and(file_get_contents($path))->toBe('must survive');
    } finally {
        unlink($path);
        if (is_link($link)) {
            unlink($link);
        }
    }
})->with(['traversal', 'symlink', 'missing', 'unreserved']);

it('checks the live SQLite connection before allowing refresh', function (bool $matching) {
    $path = '/tmp/opencode/jobora38-'.bin2hex(random_bytes(6)).'-testing.sqlite';
    touch($path);
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $path, 'database.connections.sqlite.url' => null]);
    DB::purge('sqlite');
    $connection = DB::connection();
    if (! $matching) {
        $connection->setPdo(new PDO('sqlite::memory:'));
    }
    try {
        if ($matching) {
            DisposableHomologationDatabase::assertSafe();
            expect($connection->selectOne('PRAGMA database_list')->file)->toBe($path);
        } else {
            expect(fn () => DisposableHomologationDatabase::assertSafe())->toThrow(RuntimeException::class, 'efetivo difere');
        }
    } finally {
        DB::purge('sqlite');
        unlink($path);
    }
})->with([true, false]);
