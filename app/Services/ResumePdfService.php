<?php

namespace App\Services;

use App\Enums\ResumeLanguage;
use App\Models\ResumeAdaptation;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * PDF da versão adaptada do currículo (F6).
 *
 * Derivado sob demanda do Blade de impressão — nada é persistido.
 * O driver é definido por LARAVEL_PDF_DRIVER (cloudflare → Cloudflare
 * Browser Run); nos testes o facade é trocado por Pdf::fake().
 */
class ResumePdfService
{
    public function download(ResumeAdaptation $adaptation): PdfBuilder
    {
        $language = $adaptation->language;

        return Pdf::view('pdf.adapted-resume', [
            'adaptation' => $adaptation,
            'language' => $language->value,
            'label' => $language->documentLabel(),
        ])
            ->format('a4')
            ->margins(16, 16, 16, 16)
            ->name($language === ResumeLanguage::En ? 'resume.pdf' : 'curriculo-adaptado.pdf');
    }
}
