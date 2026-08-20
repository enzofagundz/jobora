<?php

namespace App\Services;

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
        return Pdf::view('pdf.adapted-resume', [
            'adaptation' => $adaptation,
        ])
            ->format('a4')
            ->margins(16, 16, 16, 16)
            ->name('curriculo-adaptado.pdf');
    }
}
