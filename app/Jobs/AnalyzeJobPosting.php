<?php

namespace App\Jobs;

use App\Models\JobPosting;
use App\Services\JobAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeJobPosting implements ShouldQueue
{
    use Queueable;

    public function __construct(public JobPosting $posting) {}

    public function handle(JobAnalysisService $service): void
    {
        // O service trata falhas internamente: erro transitório do provedor
        // mantém a vaga pendente; falha real marca failed — sem retry aqui.
        $service->analyze($this->posting);
    }
}
