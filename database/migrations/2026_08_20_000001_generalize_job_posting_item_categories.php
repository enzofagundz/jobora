<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy data contains FK orphans (rows whose job_posting_id was deleted with foreign_keys
        // disabled), so the copy must run with FK checks off. PRAGMA foreign_keys is a connection
        // setting: disabling it before BEGIN keeps it off inside the transaction, and the
        // transaction still gives atomicity for the table rebuild.
        Schema::withoutForeignKeyConstraints(function () {
            DB::transaction(function () {
                // SQLite cannot alter a check constraint, so rebuild the table with the 5-value enum.
                // The dev-specific categories are remapped to "skill" during the copy; doing it as a
                // standalone UPDATE first would violate the existing (job_posting_id, category, name)
                // unique index when two rows collapse onto the same "skill" row.
                //
                // SQLite keeps index names when a table is renamed, so free the canonical index names
                // before renaming — otherwise creating the new table's indexes below would collide.
                Schema::table('job_posting_items', function (Blueprint $table) {
                    $table->dropUnique('job_posting_items_job_posting_id_category_name_unique');
                    $table->dropIndex('job_posting_items_category_name_index');
                });

                Schema::rename('job_posting_items', 'job_posting_items_legacy');

                Schema::create('job_posting_items', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
                    $table->enum('category', ['skill', 'tool', 'methodology', 'requirement', 'experience']);
                    $table->string('name', 100);
                    $table->enum('source', ['ai', 'user'])->default('ai');
                    $table->timestamps();

                    $table->unique(['job_posting_id', 'category', 'name']);
                    $table->index(['category', 'name']);
                });

                // Copy rows with category remap, deduping collisions: user-edited rows first, then lowest id.
                DB::statement(<<<'SQL'
                    INSERT OR IGNORE INTO job_posting_items
                        (id, job_posting_id, category, name, source, created_at, updated_at)
                    SELECT id, job_posting_id,
                           CASE WHEN category IN ('technology','language','framework','database','cloud')
                                THEN 'skill' ELSE category END,
                           name, source, created_at, updated_at
                    FROM job_posting_items_legacy
                    ORDER BY CASE WHEN source = 'user' THEN 0 ELSE 1 END, id
                SQL);

                Schema::drop('job_posting_items_legacy');

                // Defensive: remap old categories inside job_postings.suggested_data JSON.
                $map = ['technology' => 'skill', 'language' => 'skill', 'framework' => 'skill', 'database' => 'skill', 'cloud' => 'skill'];

                foreach (DB::table('job_postings')->whereNotNull('suggested_data')->cursor() as $posting) {
                    $data = json_decode($posting->suggested_data, true);

                    foreach ($data['items'] ?? [] as &$item) {
                        $item['category'] = $map[$item['category']] ?? $item['category'];
                    }

                    DB::table('job_postings')->where('id', $posting->id)->update(['suggested_data' => json_encode($data)]);
                }
            });
        });
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            DB::transaction(function () {
                // Best-effort reverse: data stays collapsed to the 5 values (irreversible).
                Schema::table('job_posting_items', function (Blueprint $table) {
                    $table->dropUnique('job_posting_items_job_posting_id_category_name_unique');
                    $table->dropIndex('job_posting_items_category_name_index');
                });

                Schema::rename('job_posting_items', 'job_posting_items_legacy_down');

                Schema::create('job_posting_items', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
                    $table->enum('category', [
                        'technology', 'language', 'framework', 'database', 'tool', 'cloud',
                        'methodology', 'skill', 'requirement', 'experience',
                    ]);
                    $table->string('name', 100);
                    $table->enum('source', ['ai', 'user'])->default('ai');
                    $table->timestamps();

                    $table->unique(['job_posting_id', 'category', 'name']);
                    $table->index(['category', 'name']);
                });

                DB::statement(<<<'SQL'
                    INSERT OR IGNORE INTO job_posting_items
                        (id, job_posting_id, category, name, source, created_at, updated_at)
                    SELECT id, job_posting_id, category, name, source, created_at, updated_at
                    FROM job_posting_items_legacy_down
                SQL);

                Schema::drop('job_posting_items_legacy_down');
            });
        });
    }
};
