<?php

use App\Enums\ResumeLanguage;
use App\Mcp\Resources\JobPostingResource;
use App\Mcp\Resources\ResumeResource;
use App\Mcp\Servers\JoboraServer;
use App\Models\JobPosting;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('expõe o currículo atual como resource', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    JoboraServer::resource(ResumeResource::class)
        ->assertOk()
        ->assertSee(['Currículo com PHP.']);
});

it('expõe o detalhe de uma vaga como resource', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create(['role' => 'Dev PHP']);
    McpTestHelpers::item($posting, 'PHP');

    JoboraServer::resource(JobPostingResource::class, ['id' => $posting->id])
        ->assertOk()
        ->assertSee(['Dev PHP', 'PHP']);
});
