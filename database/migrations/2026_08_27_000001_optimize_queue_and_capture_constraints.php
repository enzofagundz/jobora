<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jobs: composite index for pop() query
        // getNextAvailableJob: where queue, where (reserved_at null and available_at <= now) or reserved_at <= expiration, order by id
        try {
            Schema::table('jobs', function (Blueprint $table) {
                $table->index(['queue', 'reserved_at', 'available_at'], 'jobs_queue_reserved_available_index');
            });
        } catch (Throwable $e) {
            // Index may already exist
        }

        // job_captures.job_posting_id unique (nullable) to enforce idempotent promotion
        // SQLite allows multiple NULLs in unique, so captures without posting remain unaffected.
        $hasDuplicatePosting = DB::table('job_captures')
            ->whereNotNull('job_posting_id')
            ->select('job_posting_id')
            ->groupBy('job_posting_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicatePosting) {
            // Clean duplicates: keep earliest capture per posting
            $duplicates = DB::table('job_captures')
                ->whereNotNull('job_posting_id')
                ->select('job_posting_id')
                ->groupBy('job_posting_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('job_posting_id');

            foreach ($duplicates as $postingId) {
                $ids = DB::table('job_captures')
                    ->where('job_posting_id', $postingId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->all();

                // Keep first, nullify others (they remain as captures but not promoted)
                $keep = array_shift($ids);

                if ($ids !== []) {
                    DB::table('job_captures')->whereIn('id', $ids)->update(['job_posting_id' => null]);
                }
            }
        }

        try {
            Schema::table('job_captures', function (Blueprint $table) {
                $table->unique('job_posting_id', 'job_captures_job_posting_id_unique');
            });
        } catch (Throwable $e) {
            // Unique may already exist or other error
        }
    }

    public function down(): void
    {
        try {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropIndex('jobs_queue_reserved_available_index');
            });
        } catch (Throwable $e) {
        }

        try {
            Schema::table('job_captures', function (Blueprint $table) {
                $table->dropUnique('job_captures_job_posting_id_unique');
            });
        } catch (Throwable $e) {
        }
    }
};
