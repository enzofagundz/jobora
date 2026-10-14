<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\McpUserResolver;
use App\Services\ResumeService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Uri;

#[Uri('jobora://resume')]
#[Description('Currículo do usuário configurado, no idioma pedido (padrão pt-BR): texto e data de atualização.')]
class ResumeResource extends JoboraResource
{
    public function __construct(
        McpUserResolver $users,
        private readonly ResumeService $resumes,
    ) {
        parent::__construct($users);
    }

    protected function read(Request $request): Response
    {
        $resume = $this->resumes->current($this->user(), $this->language($request));

        return $this->ok([
            'content' => $resume?->content,
            'updated_at' => $resume?->updated_at?->toIso8601String(),
        ]);
    }
}
