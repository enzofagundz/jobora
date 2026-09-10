<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\System\GetAiOperationTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Jobora')]
#[Version('0.1.0')]
#[Instructions(<<<'MARKDOWN'
    Servidor MCP do Jobora, um sistema pessoal de análise do mercado de trabalho a partir de vagas.

    Regras de uso:
    - A identidade é fixa (variável JOBORA_MCP_USER): todas as tools operam o usuário configurado e nunca aceitam user_id.
    - Operações de escrita destrutivas ou de sobrescrita exigem o parâmetro confirm=true.
    - Operações de IA (análise, triagem, adequação, ATS, competências) são assíncronas: a tool enfileira e retorna; acompanhe o estado com get_job_posting, get_job_capture ou get_ai_operation. É preciso um worker ativo no Jobora.
    - Só vagas com status "confirmed" compõem as análises de mercado.
    MARKDOWN)]
final class JoboraServer extends Server
{
    protected array $tools = [
        GetAiOperationTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
