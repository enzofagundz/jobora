# 2. Pré-filtro Clef Flash na triagem de capturas

- **Status:** aceito; superfície de triagem V1 parcialmente superada pelo V2 (#37)
- **Data:** 2026-11-03
- **Glossário:** CONTEXT.md
- **Relacionado:** #8, #9, #10, #11, #12, #13

## Contexto

**Atualização V2 (#37):** este ADR registra o pré-filtro e a triagem de `JobCapture` V1. As ferramentas de captura, triagem e promoção V1 não pertencem mais ao catálogo MCP principal. A ingestão de envelopes (`ingest_page_capture`) e a solicitação explícita de triagem de publicações (`request_publication_triage`) têm contratos próprios: não são aliases desse fluxo. Clef não autoriza triagem automática. A retirada da superfície V1 não apaga as evidências abaixo nem elimina normalização, competências ou adequação compartilhadas.

A triagem de capturas é um único prompt de chat por lote de até 50 publicações: o agente decide `relevant`, `rejected`, `not_a_job` ou `uncertain` para cada uma. No banco local de 2026-11-03, 791 capturas estavam registradas — 729 concluídas, das quais 366 eram `not_a_job`. Ou seja, cerca de metade do custo do LLM era gasto para responder uma pergunta binária ("isto é uma vaga?") que um classificador calibrado e mais barato resolve.

O problema não era só de custo: a decisão era praticamente irreversível. `JobTriageService::startBatch()` só reaceita capturas `pending`/`failed` e o filtro padrão da lista esconde `not_a_job`, então um falso negativo do corte ficava invisível e inalcançável pela interface.

## Decisão

1. **Pré-filtro binário antes do agente.** Com a flag ligada, cada captura passa pelo Clef Flash no Workers AI; só as aprovadas chegam ao `JobTriageAgent`. O agente continua sendo a autoridade sobre preferências e rejeições.
2. **Cloudflare como provedora de IA fora do laravel/ai.** A chamada usa `POST /accounts/{id}/ai/run/@cf/cloudflare/clef-flash` com HTTP direto (`Http::pool`, concorrência 5), sem passar pelo `AiRequestGate`. O SDK continua sendo a via do agente principal e da adequação.
3. **Validação estrita.** O envelope precisa de `success === true` e `result.answers`; `is_job` precisa ser `noul` numérico em [0,1]; `non_job_kind` precisa ser `choice` entre as cinco categorias configuradas, com probabilidades nas mesmas chaves somando 1 ± 0,01 e `confidence` em [0,1]. Qualquer desvio vira `PrefilterException`.
4. **Corte configurável.** Barra quando `is_job < triage.prefilter.min_job_probability` (0,50 por padrão). A comparação é estrita: exatamente 0,50 não barra.
5. **Fail-open por captura.** Erro de conexão, timeout, HTTP ≥ 400, envelope inválido ou resposta fora do contrato não barram a captura: ela segue para o agente. O lote nunca falha por causa do pré-filtro; apenas os ids afetados vão para o log de IA.
6. **Flag de rollout.** `triage.prefilter.enabled` (ligada por padrão) permite desligar o pré-filtro e reproduzir o fluxo anterior sem chamada ao Workers AI.
7. **Rastro da decisão.** `job_captures.triage_origin` distingue `prefilter` de `llm`; `TriageOrigin` expõe os rótulos na UI. A migração preenche `llm` nas capturas concluídas antes da mudança. A barrada grava `triage_reason` no formato `Pré-filtro: {categoria} (p=0,xx)`.
8. **Um aviso por lote.** O último job do encadeamento — triagem ou pré-filtro, quando tudo foi barrado — emite uma única notificação com encaminhadas, barradas e falhas.
9. **Reversibilidade.** A ação em lote "Reavaliar com IA" reabre apenas `not_a_job` de origem `prefilter` e não promovidas, limpa resultado/justificativa/origem e reenfileira pelo caminho normal de triagem pulando o pré-filtro.

## Consequências

- A Cloudflare passa a ser usada como **IA** fora do Laravel AI SDK no caminho de triagem, com HTTP direto ao Workers AI; a validação estrita espelha o classificador do `daily-content-mcp`.
- Uma nova dependência operacional: conta/token da Cloudflare com permissão de Workers AI. Sem `TRIAGE_CLOUDFLARE_*`, valem `CLOUDFLARE_ACCOUNT_ID`/`CLOUDFLARE_API_TOKEN` (as mesmas do PDF/Browser Run).
- O custo do lote passa a ser N chamadas de pré-filtro + 1 chamada de triagem, documentado no modal e na descrição da tool MCP.
- O log de IA ganha a operação `job_triage_prefilter` com `barred_count`, `approved_count`, `prefilter_failed_ids` e latência.
- O comportamento com a flag desligada permanece idêntico ao anterior, sem nenhuma chamada ao Workers AI.
- A lista de capturas ganha a marca `· pré` no resultado, o motivo no tooltip de `not_a_job` e "Origem da triagem" na infolist.

## Alternativas descartadas

- **Substituir o agente pelo Clef:** o classificador responde só "anuncia vaga?"; preferências, rejeições e a distinção `relevant`/`rejected`/`uncertain` continuam exigindo o agente.
- **Manter apenas o LLM:** preserva a simplicidade, mas mantém o custo de ~50% das capturas para uma pergunta binária e não resolve o falso negativo invisível.
- **Fila ou limite dedicados para o pré-filtro:** adiciona infraestrutura sem ganho: o job próprio, encadeado e com `ShouldBeUnique`, já isola a etapa.
- **Guardar o pré-filtro em `ai_operations`:** duplicaria o rastro operacional; a decisão cabe em `triage_origin` e no log de IA.
- **Corte fixo em 0,50:** impediria calibrar o falso negativo sem deploy; a flag e o threshold ficam em configuração.
