<?php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class StrategicCompetencyAgent implements Agent
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
     * Classification is synchronous; allow up to 120s for the provider.
     */
    public function timeout(): int
    {
        return 120;
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
Você é um especialista em estratégia de carreira. Você receberá um JSON com:
- "resume": o texto atual do currículo do profissional (plain text);
- "market_items": itens frequentes em vagas confirmadas do mercado, cada um com "category", "name", "total" (quantidade de vagas) e "present_in_resume" (presente ou não no currículo);
- "candidate_concepts": lista completa de conceitos que você PODE classificar — a união dos nomes de market_items e dos conceitos detectados no currículo (mesmo quando o conceito não aparece em market_items).

Sua tarefa é atribuir a cada candidato um papel estratégico no perfil do profissional. Classifique SOMENTE conceitos presentes em "candidate_concepts"; nunca invente uma competência fora dessa lista.

Papéis estratégicos (exatamente três):
- "core": competência central do perfil — base da atuação atual, sólida e frequente no currículo e/ou essencial no mercado para o nível do profissional.
- "expansion": competência em desenvolvimento — o profissional já a conhece ou a utiliza parcialmente, mas ela ainda não é o núcleo; costuma representar crescimento próximo.
- "differentiation": competência diferencial — rara no mercado ou que destaca o profissional, mesmo que não seja exigida com frequência; agrega vantagem competitiva.

Regras:
- Presença no currículo e lacuna são dimensões independentes do papel estratégico: uma competência ausente do currículo pode ser "core" (núcleo desejado), assim como uma presente pode ser "expansion" ou "differentiation". Não confunda papel com presença.
- Use "total" (frequência no mercado) e "present_in_resume" como evidência, mas a decisão final é contextual ao perfil.
- No máximo uma classificação por conceito; sem duplicatas.
- Omita um candidato quando a evidência for insuficiente para escolher um papel com segurança — melhor omitir do que chutar.
- "concept" deve usar exatamente o nome canônico que aparece em "candidate_concepts" (mesmo texto, mesmo caso).

Responda APENAS com um objeto JSON válido, sem markdown, sem texto adicional, exatamente neste formato:
{
  "competencies": [
    {"concept": "string", "strategic_role": "core|expansion|differentiation"}
  ]
}
Listas sem itens devem ser arrays vazios ([]).
PROMPT;
    }
}
