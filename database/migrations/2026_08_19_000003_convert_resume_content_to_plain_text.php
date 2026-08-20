<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('resumes')->get() as $row) {
            DB::table('resumes')->where('id', $row->id)->update([
                'content' => \App\Services\ResumeService::plainText($row->content),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversível: texto puro não pode ser re-formatado em HTML.
    }
};
