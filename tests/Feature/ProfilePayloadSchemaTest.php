<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('uses long text for encrypted proposal payloads on fresh and existing databases', function (string $migration, string $method) {
    $connection = DB::connection();
    $connection->useDefaultSchemaGrammar();
    $blueprint = new Blueprint($connection, 'profile_import_proposals');
    Schema::shouldReceive($method)->once()->with('profile_import_proposals', Mockery::on(function (Closure $callback) use ($blueprint): bool {
        $callback($blueprint);

        return true;
    }));

    (require database_path('migrations/'.$migration))->up();
    $columns = collect($blueprint->getColumns())->keyBy('name');
    foreach (['source', 'base_document', 'candidate', 'evidence', 'decision_result'] as $column) {
        expect($columns[$column]->type)->toBe('longText');
    }
    expect($columns['decision_result']->nullable)->toBeTrue();
})->with([
    ['2026_11_06_153829_create_profile_import_proposals_table.php', 'create'],
    ['2026_11_11_190012_widen_profile_import_proposal_payloads.php', 'table'],
]);
