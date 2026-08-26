<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Owner legado: menor users.id (na base auditada é o Admin semeado).
            $ownerId = DB::table('users')->min('id');

            $hasJobPostings = DB::table('job_postings')->exists();
            $hasJobCaptures = DB::table('job_captures')->exists();
            $hasResumes = DB::table('resumes')->exists();
            $hasAdaptations = DB::table('resume_adaptations')->exists();

            if (($hasJobPostings || $hasJobCaptures || $hasResumes || $hasAdaptations) && $ownerId === null) {
                throw new RuntimeException('Backfill abortado: existem registros privados mas nenhum usuário para receber ownership. Crie um usuário antes de migrar.');
            }

            if ($ownerId === null) {
                return;
            }

            // Verificação de órfãos inesperados (único caso esperado é resume_adaptations órfã).
            $orphanItems = DB::table('job_posting_items')
                ->leftJoin('job_postings', 'job_postings.id', '=', 'job_posting_items.job_posting_id')
                ->whereNull('job_postings.id')
                ->exists();

            if ($orphanItems) {
                throw new RuntimeException('Backfill abortado: encontrados job_posting_items órfãos (job_posting_id sem vaga). Diagnóstico necessário antes de prosseguir.');
            }

            $orphanMethods = DB::table('application_methods')
                ->leftJoin('job_postings', 'job_postings.id', '=', 'application_methods.job_posting_id')
                ->whereNull('job_postings.id')
                ->exists();

            if ($orphanMethods) {
                throw new RuntimeException('Backfill abortado: encontrados application_methods órfãos. Diagnóstico necessário.');
            }

            $orphanApplications = DB::table('applications')
                ->leftJoin('job_postings', 'job_postings.id', '=', 'applications.job_posting_id')
                ->whereNull('job_postings.id')
                ->exists();

            if ($orphanApplications) {
                throw new RuntimeException('Backfill abortado: encontrados applications órfãos. Diagnóstico necessário.');
            }

            $orphanEvents = DB::table('application_events')
                ->leftJoin('applications', 'applications.id', '=', 'application_events.application_id')
                ->whereNull('applications.id')
                ->exists();

            if ($orphanEvents) {
                throw new RuntimeException('Backfill abortado: encontrados application_events órfãos. Diagnóstico necessário.');
            }

            $orphanCaptures = DB::table('job_captures')
                ->leftJoin('job_postings', 'job_postings.id', '=', 'job_captures.job_posting_id')
                ->whereNotNull('job_captures.job_posting_id')
                ->whereNull('job_postings.id')
                ->exists();

            if ($orphanCaptures) {
                throw new RuntimeException('Backfill abortado: encontradas job_captures com job_posting_id não nulo órfão. Diagnóstico necessário.');
            }

            // Backfill de user_id nos três agregados raiz (apenas onde ainda é null).
            DB::table('job_postings')->whereNull('user_id')->update(['user_id' => $ownerId]);
            DB::table('job_captures')->whereNull('user_id')->update(['user_id' => $ownerId]);
            DB::table('resumes')->whereNull('user_id')->update(['user_id' => $ownerId]);

            // Arquivar resume_adaptations órfãs (job_posting_id sem vaga correspondente).
            $orphanAdaptations = DB::table('resume_adaptations')
                ->leftJoin('job_postings', 'job_postings.id', '=', 'resume_adaptations.job_posting_id')
                ->whereNull('job_postings.id')
                ->select('resume_adaptations.*')
                ->get();

            foreach ($orphanAdaptations as $row) {
                DB::table('legacy_resume_adaptations')->insert([
                    'legacy_id' => $row->id,
                    'user_id' => $ownerId,
                    'legacy_job_posting_id' => $row->job_posting_id,
                    'adapted_content' => $row->adapted_content,
                    'changes' => $row->changes,
                    'covered_keywords' => $row->covered_keywords,
                    'missing_requirements' => $row->missing_requirements,
                    'alerts' => $row->alerts,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'archived_at' => now(),
                ]);
            }

            if ($orphanAdaptations->isNotEmpty()) {
                $ids = $orphanAdaptations->pluck('id')->all();
                DB::table('resume_adaptations')->whereIn('id', $ids)->delete();
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Down protegido: backfill de ownership e arquivamento de legado são irreversíveis sem perda de distinção original.');
    }
};
