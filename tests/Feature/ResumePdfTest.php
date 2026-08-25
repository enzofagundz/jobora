<?php

use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\ResumePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

uses(RefreshDatabase::class);

function pdfAdaptation(): ResumeAdaptation
{
    $posting = JobPosting::factory()->confirmed()->create([
        'description' => 'Vaga para desenvolvedor Python com React.',
        'role' => 'Desenvolvedor Python',
    ]);

    return ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => "ENZO FAGUNDES\n+55 14 99757-2550 | enzofagundz@gmail.com | Lins, SP\nlinkedin.com/in/enzofagundz\n\nEXPERIÊNCIA PROFISSIONAL\n\nANALISTA DE DESENVOLVIMENTO | Sonnitech | 2024 - Atual\nDesenvolvimento de sistemas web com Laravel.\n\nHABILIDADES TÉCNICAS\n- Back-end: PHP, Laravel, APIs REST\n- Front-end: Vue.js, Livewire",
        'changes' => ['Resumo reescrito'],
        'covered_keywords' => ['Python'],
        'missing_requirements' => ['React'],
        'alerts' => [],
    ]);
}

it('derives the PDF from the print blade without persisting anything', function () {
    Pdf::fake();

    $adaptation = pdfAdaptation();

    $response = app(ResumePdfService::class)
        ->download($adaptation)
        ->toResponse(request());

    expect($response)->toBeInstanceOf(Response::class);

    Pdf::assertRespondedWithPdf(function (PdfBuilder $pdf) use ($adaptation) {
        return $pdf->viewName === 'pdf.adapted-resume'
            && $pdf->viewData['adaptation']->is($adaptation);
    });
});

it('renders a professional resume without internal metadata', function () {
    Pdf::fake();

    $adaptation = pdfAdaptation();

    app(ResumePdfService::class)
        ->download($adaptation)
        ->save(sys_get_temp_dir().'/resume-pdf-test.pdf');

    Pdf::assertSee([
        'ENZO FAGUNDES',
        'EXPERIÊNCIA PROFISSIONAL',
        'HABILIDADES TÉCNICAS',
        'Sonnitech',
        '2024 - Atual',
    ]);

    Pdf::assertDontSee([
        'Versão adequada',
        '<h1>Currículo</h1>',
        'Desenvolvedor Python', // role da vaga — metadado interno
    ]);
});

it('redireciona com notificação quando a geração do PDF falha', function () {
    // Força o driver cloudflare sem credenciais, independente do .env.
    config()->set('laravel-pdf.driver', 'cloudflare');
    config()->set('laravel-pdf.cloudflare', ['api_token' => '', 'account_id' => '']);

    $adaptation = pdfAdaptation();
    $posting = $adaptation->jobPosting;

    $this->actingAs(User::factory()->create())
        ->from(JobPostingResource::getUrl('application', ['record' => $posting]))
        ->get(route('filament.admin.resume-pdf.download', $posting))
        ->assertRedirect(JobPostingResource::getUrl('application', ['record' => $posting]))
        ->assertSessionHas('filament.notifications');
});
