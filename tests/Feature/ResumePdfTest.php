<?php

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
        'adapted_content' => "Currículo adaptado.\nDestaque para Python.",
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

it('redireciona com notificação quando a geração do PDF falha', function () {
    // Força o driver cloudflare sem credenciais, independente do .env.
    config()->set('laravel-pdf.driver', 'cloudflare');
    config()->set('laravel-pdf.cloudflare', ['api_token' => '', 'account_id' => '']);

    $adaptation = pdfAdaptation();
    $posting = $adaptation->jobPosting;

    $this->actingAs(User::factory()->create())
        ->from(url('/job-postings/'.$posting->id.'/adapt'))
        ->get(route('filament.admin.resume-pdf.download', $posting))
        ->assertRedirect(url('/job-postings/'.$posting->id.'/adapt'))
        ->assertSessionHas('filament.notifications');
});
