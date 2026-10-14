# Contexto de domínio

Vocabulário compartilhado do projeto. Só termos e significados — não é spec, plano nem decisão de implementação. A UI é pt-BR e o código é inglês; o termo canônico é o de UI, com o identificador do código entre parênteses.

## Currículo

- **Currículo** (`Resume`): o texto do perfil profissional do candidato. Não há histórico: um novo envio substitui a versão anterior.
- **Versão de currículo**: o currículo em um idioma específico. Hoje existem duas: PT-BR e EN. Cada versão tem texto próprio, editável.
- **Currículo canônico**: a versão PT-BR. É a fonte de verdade do perfil — comparação de mercado, competências estratégicas e análise de mercado leem sempre ela.
- **Versão EN**: versão em inglês, gerada pelo sistema a partir do PT-BR por **tradução de IA**. Editável à mão. Não é fonte de verdade: existe para candidatura em inglês.
- **Tradução do currículo** (`ResumeTranslation`): operação de IA que gera ou regenera a versão EN a partir do PT-BR. Substitui o texto EN inteiro.
- **Drift da versão EN**: estado da versão EN quando o PT-BR mudou depois da última tradução. Não bloqueia uso; é sinalizado e pode ser regenerado.

## Vaga e candidatura

- **Vaga** (`JobPosting`): anúncio de emprego capturado e revisado pelo usuário. Só uma vaga **confirmada** permite adequar currículo.
- **Adequação de currículo** (`ResumeAdaptation`): versão do currículo adaptada a uma vaga. Existe **uma por (vaga, idioma)** — no máximo duas: PT-BR e EN. Regenerar substitui a daquele idioma, sem histórico.
- **Adaptação PT-BR** / **Adaptação EN**: as duas adaptações da mesma vaga, uma por idioma. A UI mostra uma por vez, escolhida pelo seletor de idioma.
- **Candidatura** (`Application`): o acompanhamento da candidatura a uma vaga, iniciado sob demanda. O idioma não é atributo da candidatura — quem carrega idioma é a adequação.
- **Movimentação** (`ApplicationEvent`): registro append-only do histórico da candidatura (status, observação, contato).
- **Forma de candidatura** (`ApplicationMethod`): canal pelo qual se candidatar (LinkedIn, e-mail, URL, telefone…).
- **Otimização ATS** (`ats_prompt`): bloco de palavras-chave sugerido para vencer triagens automáticas, **por adaptação** — logo, por idioma. O bloco sai no idioma da adaptação.
- **Simulação ATS** (`ats_score`): nota estimada de aderência ao ATS, **por adaptação**; a justificativa exibida permanece em pt-BR (UI).
- **Competência estratégica** (`ProfessionalCompetency`): conceito do perfil classificado contra o mercado; derivado do currículo canônico.
- **Comparação de mercado**: leitura do currículo canônico contra a frequência de conceitos das vagas analisadas.
