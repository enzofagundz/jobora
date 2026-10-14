<?php

namespace Tests\Feature\Mcp;

use App\Enums\ConceptCategory;
use App\Mcp\Servers\JoboraServer;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\ResumeAdaptation;
use App\Models\User;
use Closure;
use Laravel\Mcp\Server\Contracts\Transport;
use LogicException;

/**
 * Helpers compartilhados dos testes de MCP. A identidade do servidor é
 * resolvida por config('jobora.mcp.user'), então os testes apontam a config
 * para o usuário desejado em vez de autenticar uma sessão.
 */
final class McpTestHelpers
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function user(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        config(['jobora.mcp.user' => $user->email]);

        return $user;
    }

    /**
     * Lê um resource pelo caminho real do protocolo JSON-RPC (`resources/read`
     * apenas com `uri`), sem injetar `arguments` — que o protocolo não envia.
     * Devolve o payload decodificado (`{ok, data}` ou `{ok:false, error}`).
     *
     * @return array<string, mixed>
     */
    public static function readResource(string $uri): array
    {
        $transport = new class implements Transport
        {
            /** @var array<int, string> */
            public array $sent = [];

            public function onReceive(Closure $handler): void {}

            public function run()
            {
                throw new LogicException('Not implemented.');
            }

            public function send(string $message, ?string $sessionId = null): void
            {
                $this->sent[] = $message;
            }

            public function sessionId(): ?string
            {
                return null;
            }

            public function stream(Closure $stream): void
            {
                $stream();
            }
        };

        $server = app(JoboraServer::class, ['transport' => $transport]);
        $server->start();
        $server->handle((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => $uri],
        ]));

        $decoded = json_decode($transport->sent[0] ?? '{}', true);
        $text = $decoded['result']['contents'][0]['text'] ?? null;

        return is_string($text) ? (array) json_decode($text, true) : (array) $decoded;
    }

    public static function item(JobPosting $posting, string $name, ConceptCategory $category = ConceptCategory::Skill): JobPostingItem
    {
        return JobPostingItem::factory()->for($posting, 'jobPosting')->create([
            'category' => $category,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function adaptation(User $user, JobPosting $posting, array $attributes = []): ResumeAdaptation
    {
        return ResumeAdaptation::create(array_merge([
            'job_posting_id' => $posting->id,
            'adapted_content' => 'Currículo adaptado com PHP.',
            'changes' => ['Resumo reescrito'],
            'covered_keywords' => ['PHP'],
            'missing_requirements' => ['React'],
            'alerts' => [],
        ], $attributes));
    }
}
