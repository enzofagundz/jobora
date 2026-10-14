# 1. Versão de currículo por idioma e adequação por idioma

- **Status:** aceito
- **Data:** 2026-10-14
- **Glossário:** CONTEXT.md

## Contexto

Cada usuário tem hoje um único currículo (`resumes`, linha única por usuário) e cada vaga tem uma única adequação (`resume_adaptations`, `unique(job_posting_id)`), substituída a cada regeneração. Candidaturas em inglês exigem um currículo em inglês e uma adaptação em inglês, sem destruir as versões PT-BR.

## Decisão

1. **Uma versão de currículo por idioma.** A tabela de currículos ganha `language` com unicidade por (`user_id`, `language`). O PT-BR é o **currículo canônico**; a versão EN é derivada por tradução de IA, editável à mão e rastreada por fingerprint do PT-BR (drift sinalizado, nunca bloqueante).
2. **Uma adequação por (vaga, idioma).** A unicidade de adequação passa a ser (`job_posting_id`, `language`). Otimização ATS, simulação ATS, fingerprints e PDF são próprios de cada adaptação.
3. **O perfil continua PT-BR.** Comparação de mercado, competências estratégicas e análise de mercado leem sempre o currículo canônico; o idioma EN existe para candidatura.

## Consequências

- Toda leitura de currículo passa a declarar idioma; a superfície MCP ganha parâmetro opcional com default pt-BR.
- Staleness e fingerprints passam a ser por idioma (adaptação, ATS e tradução).
- A página de Candidatura ganha seletor de idioma; o botão "Adequar currículo" vira dropdown com um item por idioma.
- Uma operação de IA assíncrona a mais (tradução), com seu próprio lock e notificação.
- Duas adaptações coexistem por vaga; a página mostra uma por vez.
- PRD, planos e MCP_SPEC passam a registrar "uma versão adaptada por idioma, sem histórico".

## Alternativas descartadas

- **Colunas fixas `content_pt`/`content_en` na linha única**: menos joins, mas hard-coda dois idiomas e duplica campos ATS.
- **Tabela nova `resume_versions`**: separa versão de currículo-base, porém adiciona tabela e joins a todo o pipeline sem necessidade atual.
- **Adaptação única substituída por idioma**: perderia a versão anterior, contrariando o objetivo de armazenar as duas.
- **EN regenerado automaticamente a cada salvamento do PT-BR**: gasta IA sem pedido e sobrepõe edições manuais do EN sem confirmação.
- **ATS só no PT-BR**: deixaria a candidatura internacional sem otimização e sem nota.
