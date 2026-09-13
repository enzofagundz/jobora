<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\JobCapturePresenter;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\JobCaptureService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Registra uma captura de vaga (idempotente por origem + id/url/conteúdo). Use antes de triar ou promover. Retorna a captura e se ela já existia.')]
class CaptureJobTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly JobCaptureService $captures,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_id' => ['nullable', 'string', 'max:255', 'required_without_all:url,description'],
            'url' => ['nullable', 'string', 'max:2000'],
            'search_url' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:100000'],
            'company' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'author_profile_url' => ['nullable', 'string', 'max:2000'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $data = [
            'source' => $validated['source'],
            'external_id' => $validated['external_id'] ?? null,
            'url' => $validated['url'] ?? null,
            'search_url' => $validated['search_url'] ?? null,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'company' => $validated['company'] ?? null,
            'location' => $validated['location'] ?? null,
            'author_name' => $validated['author_name'] ?? null,
            'author_profile_url' => $validated['author_profile_url'] ?? null,
            'captured_at' => $validated['captured_at'] ?? now(),
        ];

        [$capture, $duplicate] = $this->captures->store($data, $this->user());
        $capture->refresh();

        return $this->ok([
            'capture' => JobCapturePresenter::toArray($capture),
            'duplicate' => $duplicate,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()->required()->description('Origem da captura (ex.: linkedin, indeed).'),
            'external_id' => $schema->string()->description('Id externo estável. Alternativo a url/description.'),
            'url' => $schema->string()->description('URL da publicação/vaga.'),
            'search_url' => $schema->string()->description('URL da busca onde a vaga foi encontrada.'),
            'title' => $schema->string()->description('Título da publicação.'),
            'description' => $schema->string()->description('Descrição da vaga (texto).'),
            'company' => $schema->string()->description('Empresa.'),
            'location' => $schema->string()->description('Localização.'),
            'author_name' => $schema->string()->description('Nome de quem publicou.'),
            'author_profile_url' => $schema->string()->description('Perfil de quem publicou.'),
            'captured_at' => $schema->string()->description('Data da captura (ISO-8601). Padrão: agora.'),
        ];
    }
}
