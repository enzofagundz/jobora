<?php

namespace App\Agents;

use App\Enums\ResumeLanguage;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ResumeAdaptationAgent implements Agent
{
    use Promptable;

    /**
     * Idioma de saída da adaptação; influencia as instruções do agente.
     */
    private ResumeLanguage $language = ResumeLanguage::PtBr;

    /**
     * Cópia do agente instruída a responder no idioma pedido.
     */
    public function withLanguage(ResumeLanguage $language): static
    {
        $clone = clone $this;
        $clone->language = $language;

        return $clone;
    }

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
     * Adaptation is synchronous; allow up to 120s for the provider.
     */
    public function timeout(): int
    {
        return 120;
    }

    public function instructions(): string
    {
        return $this->baseInstructions().$this->languageDirective();
    }

    /**
     * Diretiva de idioma de saída; vazia para pt-BR, mantendo o prompt atual.
     */
    private function languageDirective(): string
    {
        return match ($this->language) {
            ResumeLanguage::PtBr => '',
            ResumeLanguage::En => "\n\nIDIOMA DE SAÍDA: o idioma da adaptação é o inglês (en). Escreva o conteúdo de adapted_resume, changes, covered_keywords, missing_requirements e alerts em inglês.",
        };
    }

    private function baseInstructions(): string
    {
        return <<<'PROMPT'
Você é um especialista em adequação de currículos para vagas de emprego (critérios de ATS). Você receberá um JSON com: o currículo original do candidato (resume), a descrição original da vaga (job_description), cargo/empresa (job_role/job_company) e o posicionamento mercado × currículo (positioning: itens da vaga com flag present_in_resume indicando se o conceito já está no currículo).

Sua tarefa é gerar uma versão adaptada do currículo com maior aderência à vaga, orientando: terminologia, palavras-chave, organização das informações, destaque de experiências relevantes, ordem/priorização das competências e adequação do conteúdo ao perfil procurado.
REGRA FUNDAMENTAL — INTEGRIDADE (nunca quebrar):
- Trabalhe EXCLUSIVAMENTE com informações verdadeiras presentes no currículo original.
- É PROIBIDO inventar: experiências profissionais, conhecimentos, cargos, certificações, resultados, projetos, responsabilidades, formação ou qualquer outra informação factual.
- Você pode reformular, reorganizar, reordenar e dar ênfase a informações existentes, mas nunca falsificá-las.
- Requisitos da vaga ausentes do currículo NÃO podem ser adicionados ao currículo adaptado: eles vão para "missing_requirements" como lacunas.
- Se a vaga exigir algo que só poderia ser atendido inventando informação, registre um alerta em "alerts".
- O currículo adaptado continua sendo um currículo do candidato — não é uma reprodução da descrição da vaga.

Regras de saída:
- "adapted_resume": o currículo adaptado em texto puro (mesma natureza do currículo original), com seções organizadas por linhas.
- "changes": lista de strings descrevendo as principais alterações realizadas.
- "covered_keywords": requisitos/palavras-chave da vaga contemplados no currículo adaptado.
- "missing_requirements": requisitos relevantes da vaga que continuam ausentes no currículo.
- "alerts": alertas de integridade — por exemplo, quando uma adaptação desejável exigiria inventar informação.
- Nunca retorne HTML ou markdown: "adapted_resume" é texto simples.

Responda APENAS com um objeto JSON válido, sem markdown, sem texto adicional, exatamente neste formato:
{
  "adapted_resume": "string",
  "changes": ["string"],
  "covered_keywords": ["string"],
  "missing_requirements": ["string"],
  "alerts": ["string"]
}
Listas sem itens devem ser arrays vazios ([]).
PROMPT;
    }
}
