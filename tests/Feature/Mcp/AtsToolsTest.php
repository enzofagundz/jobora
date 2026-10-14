<?php

use App\Enums\ResumeLanguage;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Resources\ResumeAdaptationResource;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Ats\AdaptResumeTool;
use App\Mcp\Tools\Ats\ExportAdaptedResumePdfTool;
use App\Mcp\Tools\Ats\GetResumeAdaptationTool;
use App\Mcp\Tools\Ats\ScoreAtsTool;
use App\Mcp\Tools\Ats\SetAtsOptimizationTool;
use App\Models\JobPosting;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('devolve a adaptação de currículo de uma vaga', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting, ['adapted_content' => 'Currículo com PHP.']);

    JoboraServer::tool(GetResumeAdaptationTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.adaptation.adapted_content', 'Currículo com PHP.')
            ->where('data.adaptation.is_ats_optimized', false)
            ->where('data.adaptation.missing_requirements.0', 'React')
            ->etc());
});

it('retorna not_found quando a vaga não tem adaptação', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    JoboraServer::tool(GetResumeAdaptationTool::class, ['job_posting_id' => $posting->id])
        ->assertHasErrors(['not_found']);
});

it('enfileira a adaptação do currículo de uma vaga confirmada', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');

    JoboraServer::tool(AdaptResumeTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.status', 'queued')
            ->etc());

    Queue::assertPushed(ProcessAiOperation::class);
});

it('recusa adaptar sem currículo cadastrado', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    JoboraServer::tool(AdaptResumeTool::class, ['job_posting_id' => $posting->id])
        ->assertHasErrors(['precondition']);
});

it('ativa a otimização ATS de uma adaptação', function () {
    $user = McpTestHelpers::user(['can_use_ats_optimization' => true]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');
    McpTestHelpers::adaptation($user, $posting);

    JoboraServer::tool(SetAtsOptimizationTool::class, [
        'job_posting_id' => $posting->id,
        'enabled' => true,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.adaptation.is_ats_optimized', true)
            ->etc());
});

it('nega a otimização ATS sem permissão', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');
    McpTestHelpers::adaptation($user, $posting);

    JoboraServer::tool(SetAtsOptimizationTool::class, [
        'job_posting_id' => $posting->id,
        'enabled' => true,
    ])->assertHasErrors(['forbidden']);
});

it('enfileira a nota ATS de uma adaptação', function () {
    $user = McpTestHelpers::user(['can_use_ats_optimization' => true]);
    Queue::fake();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');
    McpTestHelpers::adaptation($user, $posting);

    JoboraServer::tool(ScoreAtsTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.status', 'queued')
            ->etc());

    Queue::assertPushed(ProcessAiOperation::class);
});

it('nega a nota ATS sem permissão', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');
    McpTestHelpers::adaptation($user, $posting);

    JoboraServer::tool(ScoreAtsTool::class, ['job_posting_id' => $posting->id])
        ->assertHasErrors(['forbidden']);
});

it('exporta o PDF da adaptação para um arquivo', function () {
    Pdf::fake();

    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting);

    JoboraServer::tool(ExportAdaptedResumePdfTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertSee(['mcp/pdf'])
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.mime_type', 'application/pdf')
            ->etc());

    Pdf::assertSaved(fn ($pdf, $path) => str_contains($path, 'mcp/pdf'));
});

it('exige consentimento para exportar o PDF otimizado', function () {
    $user = McpTestHelpers::user(['can_use_ats_optimization' => true]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');
    McpTestHelpers::adaptation($user, $posting, [
        'is_ats_optimized' => true,
        'ats_prompt' => 'Perfil compatível com PHP.',
        'ats_prompt_fingerprint' => str_repeat('a', 64),
    ]);

    JoboraServer::tool(ExportAdaptedResumePdfTool::class, ['job_posting_id' => $posting->id])
        ->assertHasErrors(['precondition']);
});

it('expõe a adaptação de currículo como resource', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting, ['adapted_content' => 'Currículo com PHP.']);

    JoboraServer::resource(ResumeAdaptationResource::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertSee(['adapted_content', 'Currículo com PHP.']);
});

it('devolve a adaptação do idioma pedido', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting, ['adapted_content' => 'Currículo PT.']);
    McpTestHelpers::adaptation($user, $posting, ['language' => 'en', 'adapted_content' => 'Resume EN.']);

    JoboraServer::tool(GetResumeAdaptationTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.adaptation.adapted_content', 'Currículo PT.')
            ->where('data.adaptation.language', 'pt-BR')
            ->etc());

    JoboraServer::tool(GetResumeAdaptationTool::class, ['job_posting_id' => $posting->id, 'language' => 'en'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.adaptation.adapted_content', 'Resume EN.')
            ->where('data.adaptation.language', 'en')
            ->etc());
});

it('enfileira a adaptação no idioma pedido usando o currículo daquele idioma', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    app(ResumeService::class)->save($user, 'Resume in English.', ResumeLanguage::En);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');

    // Sem currículo pt-BR, o idioma padrão não tem o que adaptar.
    JoboraServer::tool(AdaptResumeTool::class, ['job_posting_id' => $posting->id])
        ->assertHasErrors(['precondition']);

    JoboraServer::tool(AdaptResumeTool::class, ['job_posting_id' => $posting->id, 'language' => 'en'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.status', 'queued')
            ->etc());

    Queue::assertPushed(ProcessAiOperation::class);
});

it('ativa a otimização ATS da adaptação do idioma pedido', function () {
    $user = McpTestHelpers::user(['can_use_ats_optimization' => true]);
    app(ResumeService::class)->save($user, 'Resume with PHP.', ResumeLanguage::En);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');
    McpTestHelpers::adaptation($user, $posting, ['language' => 'en']);

    // Não existe adaptação pt-BR: o idioma padrão falha.
    JoboraServer::tool(SetAtsOptimizationTool::class, [
        'job_posting_id' => $posting->id,
        'enabled' => true,
    ])->assertHasErrors(['precondition']);

    JoboraServer::tool(SetAtsOptimizationTool::class, [
        'job_posting_id' => $posting->id,
        'enabled' => true,
        'language' => 'en',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.adaptation.is_ats_optimized', true)
            ->where('data.adaptation.language', 'en')
            ->etc());
});

it('enfileira a nota ATS da adaptação do idioma pedido', function () {
    $user = McpTestHelpers::user(['can_use_ats_optimization' => true]);
    Queue::fake();
    app(ResumeService::class)->save($user, 'Resume in English.', ResumeLanguage::En);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');
    McpTestHelpers::adaptation($user, $posting, ['language' => 'en']);

    JoboraServer::tool(ScoreAtsTool::class, ['job_posting_id' => $posting->id, 'language' => 'en'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.status', 'queued')
            ->etc());
});

it('exporta o PDF da adaptação do idioma pedido', function () {
    Pdf::fake();

    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting, ['language' => 'en', 'adapted_content' => 'Resume EN.']);

    // Sem adaptação pt-BR, o idioma padrão não encontra a versão.
    JoboraServer::tool(ExportAdaptedResumePdfTool::class, ['job_posting_id' => $posting->id])
        ->assertHasErrors(['not_found']);

    JoboraServer::tool(ExportAdaptedResumePdfTool::class, ['job_posting_id' => $posting->id, 'language' => 'en'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.mime_type', 'application/pdf')
            ->etc());

    Pdf::assertSaved(fn ($pdf, $path) => str_contains($path, 'mcp/pdf'));
});

it('expõe a adaptação do idioma pedido como resource', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting, ['adapted_content' => 'Currículo PT.']);
    McpTestHelpers::adaptation($user, $posting, ['language' => 'en', 'adapted_content' => 'Resume EN.']);

    // O idioma viaja como query na URI do template: .../{id}?language=en.
    JoboraServer::resource(ResumeAdaptationResource::class, ['job_posting_id' => $posting->id.'?language=en'])
        ->assertOk()
        ->assertSee(['Resume EN.']);
});
