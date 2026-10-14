<?php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ResumeTranslationAgent implements Agent
{
    use Promptable;

    /**
     * Provider name registered in config/ai.php.
     */
    public function provider(): string
    {
        return (string) config('ai.default');
    }

    /**
     * Model configurado via text.default do provider ativo.
     */
    public function model(): string
    {
        return (string) config('ai.providers.'.config('ai.default').'.models.text.default');
    }

    /**
     * Translation runs on the worker; allow up to 120s for the provider.
     */
    public function timeout(): int
    {
        return 120;
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
Você é um tradutor especializado em currículos profissionais. Você receberá um JSON com:
- "resume": o currículo original do candidato em português (plain text).

Sua tarefa é traduzir integralmente o currículo para o inglês, preservando o significado.
REGRA FUNDAMENTAL — INTEGRIDADE (nunca quebrar):
- Traduza EXCLUSIVAMENTE o conteúdo presente no currículo original.
- É PROIBIDO inventar: experiências profissionais, conhecimentos, cargos, certificações, resultados, projetos, responsabilidades, formação ou qualquer outra informação factual.
- Não acrescente seções, competências ou conquistas ausentes do original.
- Preserve os termos técnicos, nomes de tecnologias, empresas, cargos e datas como são reconhecidos no mercado.
- Mantenha a estrutura do currículo (mesmas seções, mesma ordem).

Regras de saída:
- "translated_resume": o currículo traduzido em texto puro, com a mesma natureza e organização do original.
- Nunca retorne HTML ou markdown: "translated_resume" é texto simples.

Responda APENAS com um objeto JSON válido, sem markdown, sem texto adicional, exatamente neste formato:
{
  "translated_resume": "string"
}
PROMPT;
    }
}
