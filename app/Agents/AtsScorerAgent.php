<?php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class AtsScorerAgent implements Agent
{
    use Promptable;

    public function provider(): string
    {
        return (string) config('ai.default');
    }

    public function model(): string
    {
        return (string) config('ai.providers.'.config('ai.default').'.models.text.default');
    }

    public function timeout(): int
    {
        return 60;
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
Você é um avaliador de ATS (Applicant Tracking System) baseado em IA. Você receberá um JSON com: currículo do candidato (resume), descrição da vaga (job_description), cargo/empresa (job_role/job_company) e lista de itens da vaga (items). Sua tarefa é avaliar a compatibilidade entre currículo e vaga, considerando cobertura de requisitos, terminologia e aderência ao perfil.

Responda APENAS com um objeto JSON válido, sem markdown, sem texto adicional, exatamente neste formato:
{
  "score": 8,
  "rationale": "string explicando a nota em até 200 caracteres"
}
Regras:
- score é inteiro de 0 a 10 (0 = incompatível, 10 = perfeitamente compatível)
- rationale é string curta (máx 200 chars) justificando a nota
- Liste rationale de forma objetiva e em português
PROMPT;
    }
}
