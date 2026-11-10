<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureConflictIdentityHash();
        $this->ensureOpportunitySourceOptionalOrigin();
    }

    public function down(): void
    {
        //
    }

    private function ensureConflictIdentityHash(): void
    {
        if (! Schema::hasColumn('publication_identity_conflicts', 'identity_hash')) {
            Schema::table('publication_identity_conflicts', function (Blueprint $table): void {
                $table->char('identity_hash', 64)->nullable();
            });
        }

        DB::table('publication_identity_conflicts')
            ->whereNull('identity_hash')
            ->orderBy('id')
            ->chunkById(500, function ($conflicts): void {
                foreach ($conflicts as $conflict) {
                    DB::table('publication_identity_conflicts')
                        ->where('id', $conflict->id)
                        ->update(['identity_hash' => hash('sha256', implode('|', [
                            $conflict->user_id,
                            $conflict->source,
                            $conflict->external_id ?? '',
                            $conflict->canonical_url ?? '',
                        ]))]);
                }
            });

        $duplicates = DB::table('publication_identity_conflicts')
            ->select('identity_hash')
            ->whereNotNull('identity_hash')
            ->groupBy('identity_hash')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('identity_hash');

        foreach ($duplicates as $identityHash) {
            $keepId = DB::table('publication_identity_conflicts')
                ->where('identity_hash', $identityHash)
                ->min('id');

            DB::table('publication_identity_conflicts')
                ->where('identity_hash', $identityHash)
                ->where('id', '<>', $keepId)
                ->delete();
        }

        $hasUniqueIndex = collect(Schema::getIndexes('publication_identity_conflicts'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['identity_hash']);

        if (! $hasUniqueIndex) {
            Schema::table('publication_identity_conflicts', function (Blueprint $table): void {
                $table->unique('identity_hash');
            });
        }
    }

    private function ensureOpportunitySourceOptionalOrigin(): void
    {
        $column = collect(Schema::getColumns('opportunity_sources'))->firstWhere('name', 'job_posting_id');
        $foreignKey = collect(Schema::getForeignKeys('opportunity_sources'))
            ->first(fn (array $key): bool => $key['columns'] === ['job_posting_id']);

        $needsNullable = $column !== null && $column['nullable'] === false;
        $needsNullOnDelete = $foreignKey !== null && strtoupper((string) $foreignKey['on_delete']) !== 'SET NULL';

        if (! $needsNullable && ! $needsNullOnDelete) {
            return;
        }

        if ($foreignKey !== null) {
            Schema::table('opportunity_sources', function (Blueprint $table): void {
                $table->dropForeign(['job_posting_id']);
            });
        }

        if ($needsNullable) {
            Schema::table('opportunity_sources', function (Blueprint $table): void {
                $table->foreignId('job_posting_id')->nullable()->change();
            });
        }

        Schema::table('opportunity_sources', function (Blueprint $table): void {
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->nullOnDelete();
        });
    }
};
