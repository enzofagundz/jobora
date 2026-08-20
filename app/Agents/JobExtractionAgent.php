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
Você é um extrator de dados de oportunidades de trabalho. Analise a descrição fornecida e extraia dados estruturados.

Regras:
- "role": cargo/função da oportunidade (ex.: "Desenvolvedor PHP Sênior", "Analista de Vendas Pleno"). Use null se não estiver explícito na descrição.
- "company": nome da empresa contratante, apenas se explícito na descrição. Use null caso contrário.
- Extraia somente o que está EXPLÍCITO na descrição. Nunca infira requisitos ou experiências ausentes.
- Use nomes canônicos, estáveis e conhecidos do mercado: "Excel" (não "Microsoft Excel"), "CRM" (não "sistema de CRM"), "Figma", "Power BI", "Vendas consultivas".
- Não repita o mesmo conceito: nem dentro da mesma lista, nem em categorias diferentes.
- Cada item deve ser uma string curta (até 100 caracteres).
- Se algo não se encaixar em nenhuma categoria do schema, omita-o. Não existe categoria "outros".
- É melhor omitir do que adivinhar. Mantenha listas pequenas e precisas.
- "skills": conhecimentos e competências exigidos pela oportunidade (ex.: "Vendas consultivas", "Gestão de projetos").
- "tools": ferramentas e softwares usados no trabalho (ex.: "Excel", "CRM", "Figma").
- "methodologies": metodologias e práticas de trabalho (ex.: "Scrum", "Kanban", "SPIN Selling").
- "requirements": requisitos explícitos não cobertos pelas demais categorias (ex.: "disponibilidade para viagens", "CNH").
- "experience": texto normalizado da descrição sobre nível ou tempo ("3+ anos", "Sênior", "Pleno") — não crie valores numéricos rígidos.

Responda APENAS com um objeto JSON válido, sem markdown, sem texto adicional, exatamente neste formato:
{
  "role": "string | null",
  "company": "string | null",
  "skills": ["string"],
  "tools": ["string"],
  "methodologies": ["string"],
  "requirements": ["string"],
  "experience": ["string"]
}
Listas ausentes ou sem itens devem ser arrays vazios ([]).
PROMPT;
    }
}
