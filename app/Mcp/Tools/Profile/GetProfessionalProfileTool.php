<?php

namespace App\Mcp\Tools\Profile;

use App\Mcp\Tools\JoboraTool;
use App\Services\ProfessionalProfileService;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lê o rascunho e a versão confirmada do perfil profissional do usuário autenticado.')]
class GetProfessionalProfileTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        Validator::validate(['arguments' => $request->all()], ['arguments' => ['array', 'max:0']]);

        return $this->ok(app(ProfessionalProfileService::class)->get($this->user()));
    }
}
