<?php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class JobExtractionAgent implements Agent
{
    use Promptable;

    /**
     * Provider name registered in config/ai.php.
     */
    public function provider(): string
    {
        return 'opencode-go';
    }

    /**
     * Model configured via AI_MODEL.
     */
    public function model(): string
    {
        return (string) config('ai.providers.opencode-go.models.text.default');
    }

    /**
     * Extraction is synchronous; allow up to 120s for the provider.
     */
    public function timeout(): int
    {
        return 120;
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
Você é um extrator de dados de vagas de emprego. Analise a descrição da vaga fornecida e extraia dados estruturados.

Regras:
- "role": cargo da vaga (ex.: "Desenvolvedor PHP Sênior"). Use null se não estiver explícito na descrição.
- "company": nome da empresa contratante, apenas se explícito na descrição. Use null caso contrário.
- Extraia somente o que está EXPLÍCITO na descrição. Nunca infira tecnologias, requisitos ou experiências ausentes.
- Use nomes canônicos, estáveis e conhecidos do mercado: "PostgreSQL" (não "Postgres"), "Laravel" (não "Laravel Framework"), "AWS" (não "Amazon Web Services"), "Node.js", "TypeScript".
- Não repita o mesmo conceito: nem dentro da mesma lista, nem em categorias diferentes.
- Cada item deve ser uma string curta (até 100 caracteres).
- Se algo não se encaixar em nenhuma categoria do schema, omita-o. Não existe categoria "outros".
- É melhor omitir do que adivinhar. Mantenha listas pequenas e precisas.
- "experience" usa texto normalizado da descrição ("3+ anos", "Sênior") — não crie valores numéricos rígidos.

Responda APENAS com um objeto JSON válido, sem markdown, sem texto adicional, exatamente neste formato:
{
  "role": "string | null",
  "company": "string | null",
  "technologies": ["string"],
  "languages": ["string"],
  "frameworks": ["string"],
  "databases": ["string"],
  "tools": ["string"],
  "cloud": ["string"],
  "methodologies": ["string"],
  "skills": ["string"],
  "requirements": ["string"],
  "experience": ["string"]
}
Listas ausentes ou sem itens devem ser arrays vazios ([]).
PROMPT;
    }
}
