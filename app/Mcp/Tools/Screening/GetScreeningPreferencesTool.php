<?php

namespace App\Mcp\Tools\Screening;

use App\Mcp\Tools\JoboraTool;
use App\Models\ScreeningPreference;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Devolve os critérios de triagem do usuário configurado: preferências e rejeições (texto livre). Rejeições têm prioridade sobre preferências.')]
class GetScreeningPreferencesTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $preference = ScreeningPreference::query()
            ->where('user_id', $this->user()->id)
            ->first();

        return $this->ok([
            'preferences' => $preference?->preferences ?? [],
            'rejections' => $preference?->rejections ?? [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
