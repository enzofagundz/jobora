<?php

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Schema;

function pr7MysqlMigrationSql(string $migration): string
{
    $schema = Schema::getFacadeRoot();
    $connection = new class(new PDO('sqlite::memory:'), 'regression') extends MySqlConnection
    {
        public function getServerVersion(): string
        {
            return '8.0.32';
        }
    };
    Schema::swap($connection->getSchemaBuilder());
    try {
        $queries = $connection->pretend(fn () => (require database_path('migrations/'.$migration))->up());

        return implode("\n", array_column($queries, 'query'));
    } finally {
        Schema::swap($schema);
    }
}

it('allocates longtext for encrypted matching payloads on fresh and deployed MySQL schemas', function (string $migration) {
    $sql = pr7MysqlMigrationSql($migration);
    foreach (['frozen', 'result', 'provenance_warnings'] as $column) {
        expect($sql)->toContain('`'.$column.'` longtext not null');
    }
})->with([
    '2026_11_06_174356_create_matching_evaluations_table.php',
    '2026_11_12_105036_widen_matching_evaluation_payloads.php',
]);
