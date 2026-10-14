# MCP_SPEC — jobora-mcp (servidor MCP do Jobora para o Hermes Agent)

> Documento de especificação. Define o servidor MCP `jobora-mcp`, que permite a um agente
> Hermes interagir com o sistema Jobora. Este documento é a fonte da implementação do MCP;
> não substitui o `PRD.md`, o `TECHNICAL_PLAN.md` nem o `IMPLEMENTATION_PLAN.md` — apenas os
> estende com uma nova superfície de integração.
>
> Escopo deste documento: especificação (contrato + arquitetura + plano). A implementação do
> código é derivada dele.

## 1. Visão e objetivo

O **jobora-mcp** é um servidor MCP (Model Context Protocol) embutido na aplicação Laravel do
Jobora. Ele expõe as capacidades do sistema como **tools**, **resources** e (não no MVP)
prompts, para que um agente **Hermes Agent** local consulte e opere o sistema.

Objetivo: transformar o Jobora em um sistema operável por um agente autônomo — capturar e
triar vagas, estruturá-las com IA, revisar/confirmar, manter currículo e competências,
consultar análises de mercado, adequar currículo para ATS e acompanhar candidaturas — sem
duplicar a lógica de negócio e respeitando a autorização do painel.

**Não-objetivo:** expor um segundo gerenciador de candidaturas, scraping, multiusuário por
chamada, transporte HTTP público, prompts guiados ou qualquer capacidade que o painel já não
tenha.

## 2. Decisões consolidadas

| # | Decisão | Escolha |
| - | ------- | ------- |
| D1 | Escopo funcional | **Copiloto completo** (leitura + escrita) |
| D2 | Transporte | **stdio local** (`php artisan mcp:start jobora`) |
| D3 | Onde vive | **Dentro do app Laravel**, com o pacote `laravel/mcp` |
| D4 | Identidade | **Usuário único configurado** por `JOBORA_MCP_USER` |
| D5 | Catálogo | **Por domínio, granularidade média** — ~37 tools cobrindo todos os domínios |
| D6 | Operações de IA | **Assíncronas**: enfileira e devolve estado; agente acompanha |
| D7 | Salvaguarda de escrita | Escrita **marcada**; destrutivas/sobrescrita exigem `confirm: true` |
| D8 | Autorização | **Respeitar Gates/Policies** existentes |
| D9 | PDF | Gera arquivo local e **retorna o caminho** |
| D10 | Contrato | snake_case por domínio + paginação padrão + **erros tipados** |
| D11 | Resources | **5 resources read-only** de contexto; sem prompts |
| D12 | Entrega | Este `MCP_SPEC.md`, em pt-BR, na raiz |

## 3. Escopo

### Dentro do escopo

- Servidor MCP local (stdio) registrado no app Laravel, com handle `jobora`.
- Tools cobrindo captura, triagem, promoção, vagas, currículo, mercado, competências
  estratégicas, adaptação/ATS, candidaturas, busca, preferências de triagem e status de IA.
- Resources read-only de contexto.
- Identidade via env, autorização via Gates/Policies, escrita destrutiva com confirmação.
- Operações de IA assíncronas com acompanhamento de estado.
- Testes Pest e integração com o Hermes (`~/.hermes/config.yaml`).

### Fora do escopo (explícito)

- Transporte HTTP/SSE, OAuth, exposição em rede e autenticação por token de rede.
- Multiusuário por chamada (uma instância MCP = um usuário configurado).
- Prompts MCP guiados.
- Novos endpoints HTTP ou mudanças na API da extensão (`/api/job-captures`).
- Scraping/crawling/browser automation e captura automática.
- DOCX, histórico de versões de currículo/adaptação e materialização de métricas.
- Alterar regras de negócio, migrations ou modelos existentes.

## 4. Arquitetura

### 4.1 Dependência

O pacote `laravel/mcp` **v0.9.4** já está instalado (transitivo via `laravel/ai`, `^0.10.3`).
O jobora-mcp passa a exigi-lo como **dependência direta**:

```bash
composer require laravel/mcp:^0.9
```

Nenhuma outra dependência. Sem Redis, filas novas ou microserviços.

### 4.2 Registro e execução

- Publicar/criar `routes/ai.php` (o `McpServiceProvider` só carrega esse arquivo se ele existir):
  ```bash
  php artisan vendor:publish --tag=ai-routes
  ```
- Registrar o servidor local no handle `jobora`:
  ```php
  // routes/ai.php
  use Laravel\Mcp\Facades\Mcp;
  use App\Mcp\Servers\JoboraServer;

  Mcp::local('jobora', JoboraServer::class);
  ```
- O Hermes sobe o servidor com `php artisan mcp:start jobora` (ver §11).
- Debug manual: `php artisan mcp:inspector jobora`.

### 4.3 Organização de pastas

Geradores do pacote usam `App\Mcp\Servers`, `App\Mcp\Tools`, `App\Mcp\Resources`.

```text
app/Mcp/
├── Servers/
│   └── JoboraServer.php           # #[Name], #[Version], #[Instructions]; lista tools/resources
├── Tools/                         # uma classe por tool
│   ├── JoboraTool.php             # base abstrata: user(), paginate(), ok(), fail()
│   ├── Captures/…
│   ├── JobPostings/…
│   ├── Resume/…
│   ├── Market/…
│   ├── Competencies/…
│   ├── Ats/…
│   ├── Applications/…
│   └── System/…
├── Resources/
│   ├── ResumeResource.php
│   ├── ProfessionalCompetenciesResource.php
│   ├── MarketSnapshotResource.php
│   ├── JobPostingResource.php
│   └── ResumeAdaptationResource.php
├── Support/
│   ├── McpUserResolver.php         # resolve o User de JOBORA_MCP_USER (cache por processo)
│   └── ToolResult.php              # shape {ok,data|codigo de erro} e helpers JSON
```

Config nova: `config/jobora.php`

```php
<?php

return [
    'mcp' => [
        // E-mail ou id numérico do usuário dono dos dados acessados via MCP.
        'user' => env('JOBORA_MCP_USER'),
    ],
];
```

### 4.4 Identidade (`JOBORA_MCP_USER`)

- `McpUserResolver::resolve(): User` lê `config('jobora.mcp.user')`.
- Aceita **e-mail** ou **id numérico**; consulta `users` e rejeita usuário inexistente ou
  desabilitado (`disabled_at`).
- O User é resolvido **uma vez por processo** e reutilizado por todas as tools/resources.
- Se a variável estiver ausente, vazia ou inválida: o servidor **falha na primeira operação**
  com erro tipado `configuration` (mensagem clara em pt-BR), sem vazar detalhes internos.
- Definir em `JOBORA_MCP_USER` no `.env` do Jobora (carregado por `php artisan`). Também é
  possível defini-la no bloco `env:` do Hermes (§11).
- `.env.example` recebe a chave vazia + comentário.

### 4.5 Autorização (Gates/Policies)

As tools **não** ignoram a autorização: chamam Services/Policies como o usuário configurado.

- `JobPostingPolicy`: `view`, `update`, `delete` → `posting.user_id === user->id`.
- `JobCapturePolicy`: `view`, `update`, `delete`, `triage`, `promote` → `capture.user_id === user->id`.
- Gate `promote` → `JobCapturePolicy@promote`.
- Gate `useAtsOptimization` → `is_admin || can_use_ats_optimization`.
- Gate `admin` → `is_admin`.

O spec documenta os flags necessários: para tools de ATS, o usuário configurado precisa de
`can_use_ats_optimization` (ou `is_admin`). Quando faltar, a tool retorna erro tipado
`forbidden` com mensagem legível apontando o flag — nunca contorna a policy.

### 4.6 Fluxo de uma chamada

```text
Hermes (stdio) → php artisan mcp:start jobora
  → JoboraServer → Tool::handle(Request)
    → McpUserResolver::resolve()            # identidade fixa
    → Gate/Policy (quando aplicável)        # autorização
    → Service de domínio existente          # regra de negócio
    → Response::json(...) | Response::error(...)
```

Regras de camada:

- **Escrita/negócio** → sempre via Services existentes (`JobCaptureService`,
  `JobPromotionService`, `JobReviewService`, `JobAnalysisService`, `JobTriageService`,
  `ResumeService`, `ResumeAdaptationService`, `AtsScorerService`, `StrategicCompetencyService`,
  `ApplicationTrackingService`, `AiOperationService`, `SearchUrlGenerator`).
- **Leitura/listagem** → preferir métodos de Service (`MarketAnalysisService`,
  `MarketComparisonService`, `ResumeAnalyzer`, `StrategicCompetencyService::rows`,
  `JobCaptureService`, etc.). Quando não existir método de leitura, usar query Eloquent
  **escopada ao `user_id`** dentro da tool (como as páginas Filament fazem), sem duplicar
  regra de negócio.
- **Criação/exclusão de vaga** (sem serviço dedicado hoje): adicionar métodos finos em
  `App\Services\JobPostingService` (`create(User,string,?string): JobPosting`,
  `delete(User,JobPosting): void`), espelhando o comportamento do Filament (status `pending`,
  `collected_at = now`, descrição 20–100.000 chars, `source_url` opcional).
- Nenhuma regra de negócio nova dentro de tools; nenhum SQL específico de driver (local SQLite,
  produção PostgreSQL) — agregar sempre via Services.

## 5. Convenções do contrato

### 5.1 Nomenclatura

- `snake_case`, prefixada pelo domínio: `list_job_postings`, `get_market_comparison`,
  `capture_job`, `confirm_job_posting`.
- Sem prefixo `jobora_` (o Hermes já namespaceia por servidor).
- Nome da classe de tool em PascalCase (`ListJobPostings`), `#[Description]` em pt-BR.

### 5.2 Entrada e validação

- Schema declarado via `schema(JsonSchema $schema): array` (tipos explícitos, `->required()`,
  enums como string com valores do enum PHP).
- Validação adicional no `handle()` com `$request->get(...)` + checagens de negócio
  (ownership, pré-condições). Nunca confiar apenas no schema do cliente.
- `language`: parâmetro opcional das tools e resources de currículo, adaptação e PDF —
  `pt-BR` (padrão quando omitido) ou `en`. Omitido equivale a `pt-BR`, então clientes atuais
  seguem funcionando sem alterar o payload. Nos resources o idioma vem do argumento
  `language` ou da query da URI (ex.: `jobora://resume?language=en`).

### 5.3 Resposta

Leitura e escrita bem-sucedidas retornam JSON estruturado via `Response::json([...])`:

```json
{ "ok": true, "data": { } }
```

- Datas em ISO-8601 (`Y-m-d\TH:i:sP`).
- Enums serializados pelos valores string (`confirmed`, `queued`, `core`…).
- `id`s numéricos.
- Quando útil, declarar `outputSchema` na tool para clientes que o suportam.

### 5.4 Erros

Falhas retornam `Response::error($text)`, onde `$text` é um JSON com código estável:

```json
{ "ok": false, "error": { "code": "not_found", "message": "Vaga não encontrada.", "hint": null } }
```

Códigos estáveis:

| Code | Quando |
| ---- | ------ |
| `validation` | Entrada inválida / pré-condição de formato |
| `not_found` | Recurso inexistente ou fora do escopo do usuário |
| `forbidden` | Gate/Policy negou (inclui flag faltando) |
| `conflict` | Estado incompatível (ex.: vaga já `confirmed`, captura já promovida, operação já ativa) |
| `precondition` | Vaga não confirmada, sem currículo, adaptação inexistente etc. |
| `ai_unavailable` | Falha ao enfileirar operação de IA |
| `configuration` | `JOBORA_MCP_USER` ausente/inválido/desabilitado |
| `internal` | Erro inesperado (logado; mensagem genérica, sem stack trace) |

Nunca retornar stack trace, SQL, credenciais ou conteúdo de `.env`.

### 5.5 Paginação

Toda tool de lista aceita:

- `limit`: inteiro, default **20**, máximo **100**.
- `offset`: inteiro, default **0**.

Resposta padrão de lista:

```json
{ "ok": true, "data": { "items": [], "total": 0, "next_offset": null } }
```

`next_offset` = `offset + limit` quando ainda houver resultados, senão `null`. Ordenação
determinística por `id` (ou `collected_at`/`occurred_at` quando fizer sentido, sempre
documentada na tool).

### 5.6 Annotations e confirmação

Usar as annotations nativas do `laravel/mcp`:

| Annotation | Uso |
| ---------- | --- |
| `#[IsReadOnly]` | tools de consulta (nenhum efeito) |
| `#[IsDestructive]` | exclusão/substituição irreversível |
| `#[IsIdempotent]` | repetir a chamada não duplica efeito (ex.: `capture_job`) |
| `#[IsOpenWorld]` | tools que disparam IA (efeito externo/custo) |

Tools destrutivas/sobrescrita exigem `confirm: true` (bool, obrigatório) como primeiro campo;
sem ele, retornam `conflict` com mensagem explicando o efeito. Conjunto com confirmação:

`delete_job_posting`, `delete_job_capture`, `discard_job_posting_suggestion`, `save_resume`,
`remove_professional_competency`.

## 6. Catálogo de tools

Legenda: **R** = read-only · **W** = escrita · **D** = destrutiva/sobrescrita (exige `confirm`)
· **IA** = dispara operação de IA (assíncrona).

### 6.1 Capturas

| Tool | Tipo | Pré-condições | Entrada | Saída | Efeitos |
| ---- | ---- | ------------- | ------- | ----- | ------- |
| `capture_job` | W, idempotente | Ao menos um de `external_id`/`url`/`description` | `source` (req, ≤50), `external_id`, `url`, `search_url`, `title`, `company`, `location`, `description` (≤100000), `author_name`, `author_profile_url`, `captured_at` | `{capture, duplicate}` | `JobCaptureService::store()`; dedup por `dedup_key` |
| `list_job_captures` | R | — | `triage_status`, `triage_result`, `promoted` (bool), `source`, `from`, `to`, `limit`, `offset` | lista | — |
| `get_job_capture` | R | Owned | `id` | captura completa | — |
| `triage_job_captures` | W, IA | Owned; não promovidas; `triage_status ∈ {pending,failed}`; lote ≤ 50 | `ids` (opcional), `max` (default 20, ≤50) | `{claimed_ids, batch_size, state:'processing'}` | `JobTriageService::startBatch()` → despacha `TriageJobCaptures` |
| `promote_job_capture` | W, IA | Owned; não promovida; `description ≥ 20`; `triage_completed` + `result ∈ {relevant,uncertain}` | `id` | `{job_posting_id, status:'pending'}` | `JobPromotionService::promote()` → cria vaga + despacha `AnalyzeJobPosting` |
| `promote_eligible_job_captures` | W, IA | Igual acima, em lote | `max` (default 20, ≤50) | `{promoted_count, job_posting_ids}` | `JobPromotionService::promoteEligible()` |
| `delete_job_capture` | D | Owned; `confirm` | `id`, `confirm` | `{deleted:true}` | Soft delete (`JobCapture`) |

**Acompanhamento:** o estado da triagem vive em `JobCapture.triage_status`/`triage_result`;
consultar com `get_job_capture`/`list_job_captures`. A promoção cria a vaga em `pending`;
consultar com `get_job_posting`.

### 6.2 Vagas

| Tool | Tipo | Pré-condições | Entrada | Saída | Efeitos |
| ---- | ---- | ------------- | ------- | ----- | ------- |
| `create_job_posting` | W | Descrição 20–100000 | `description` (req), `source_url` (opcional, ≤2000) | vaga | `JobPostingService::create()`; `pending`, `collected_at = now` |
| `list_job_postings` | R | — | `status`, `category`, `role`, `company`, `from`, `to`, `limit`, `offset` | lista (id, role, company, status, collected_at, items_count, has_adaptation, application_status) | — |
| `get_job_posting` | R | Owned | `id` | vaga + itens + métodos + sugestão/erro + resumo de adaptação/candidatura + última tentativa | — |
| `analyze_job_posting` | W, IA | Owned; `status ≠ confirmed`; descrição 20–100000 | `id` | `{job_posting_id, status:'pending'}` (enfileirado) | Despacha `AnalyzeJobPosting` (`JobAnalysisService::analyze`) |
| `confirm_job_posting` | W | Owned; `status = needs_review` | `id`, `items[{category,name}]`, `application_methods[]`, `role`, `company` | vaga confirmada | `JobReviewService::confirm()` |
| `discard_job_posting_suggestion` | D | Owned; `status = needs_review`; `confirm` | `id`, `confirm` | `{status:'pending'}` | `JobReviewService::discard()` |
| `delete_job_posting` | D | Owned; `confirm` | `id`, `confirm` | `{deleted:true}` | `JobPostingService::delete()` (cascade itens/métodos/adaptação/candidatura) |

`category ∈ ConceptCategory` (`skill`, `tool`, `methodology`, `requirement`, `experience`);
`application_methods[].type ∈ ApplicationMethodType`.

**Acompanhamento:** a análise é assíncrona; `get_job_posting.status` transita
`pending → needs_review | failed` (`error_message` preenchido na falha). `get_job_posting`
expõe `suggested_data`, `items` e `application_methods` para a revisão.

### 6.3 Currículo

| Tool | Tipo | Pré-condições | Entrada | Saída | Efeitos |
| ---- | ---- | ------------- | ------- | ----- | ------- |
| `get_resume` | R | — | `language` (opcional) | `{content, updated_at}` (ou `null`) | `ResumeService::current($user, $language)` |
| `save_resume` | D | `confirm` | `content` (req), `confirm`, `language` (opcional) | `{content, updated_at}` | `ResumeService::save()` — substitui a versão daquele idioma (uma por (usuário, idioma)) |

`language ∈ ResumeLanguage` (`pt-BR`, `en`); omitido = `pt-BR`. Salvar um idioma não toca nos demais.

### 6.4 Mercado

| Tool | Tipo | Entrada | Saída |
| ---- | ---- | ------- | ----- |
| `get_market_frequencies` | R | `category`, `from`, `to`, `limit` | `{items:[{category,name,total,percent}], total_confirmed}` |
| `get_market_pairs` | R | `category`, `limit` | `{items:[{category,name_a,name_b,total}]}` |
| `get_market_trends` | R | `period ∈ {week,month,quarter,year}`, `category`, `from`, `to` | `{series, comparison, insufficient_reason, labels}` |
| `get_market_comparison` | R | `category`, `gaps_limit` | `{table:[{name,category,total,percent,present,is_gap,strategic_role}], gaps:[...]}` |

Fontes: `MarketAnalysisService::{relativeFrequencies,pairsByCategory,periodSeries,periodComparison,periodInsufficientReason}` e `MarketComparisonService::{table,gaps}`. Somente vagas `confirmed`.

### 6.5 Competências estratégicas

| Tool | Tipo | Pré-condições | Entrada | Saída | Efeitos |
| ---- | ---- | ------------- | ------- | ----- | ------- |
| `list_professional_competencies` | R | — | — | `{items:[{id,concept,strategic_role,source,present,is_gap}]}` | `StrategicCompetencyService::rows()` |
| `classify_professional_competencies` | W, IA | Currículo cadastrado; candidatos não vazios | — | `{operation_id, status:'queued'}` | `AiOperationService::enqueueStrategicCompetencyClassification()` |
| `set_professional_competency` | W | Conceito normalizado não vazio, ≤100 | `concept`, `strategic_role ∈ {core,expansion,differentiation}` | competência | `saveManual()` (source `manual`, prevalece sobre IA) |
| `remove_professional_competency` | D | Owned; `confirm` | `id`, `confirm` | `{deleted:true}` | `StrategicCompetencyService::remove()` |

### 6.6 Adaptação / ATS

| Tool | Tipo | Pré-condições | Entrada | Saída | Efeitos |
| ---- | ---- | ------------- | ------- | ----- | ------- |
| `adapt_resume` | W, IA | Owned; `status = confirmed`; currículo do idioma cadastrado | `job_posting_id`, `language` (opcional) | `{operation_id, status:'queued'}` | `AiOperationService::enqueueResumeAdaptation()` |
| `get_resume_adaptation` | R | Owned; adaptação do idioma existente | `job_posting_id`, `language` (opcional) | `{language, adapted_content, changes, covered_keywords, missing_requirements, alerts, is_ats_optimized, ats_score, ats_rationale}` | — |
| `set_ats_optimization` | W (síncrona, sem IA) | Gate `useAtsOptimization`; adaptação do idioma existente | `job_posting_id`, `enabled` (bool), `language` (opcional) | `{adaptation}` | `ResumeAdaptationService::setAtsOptimization()` (prompt determinístico) |
| `score_ats` | W, IA | Gate `useAtsOptimization`; adaptação do idioma existente | `job_posting_id`, `language` (opcional) | `{operation_id, status:'queued'}` | `AiOperationService::enqueueAtsScore()` |
| `export_adapted_resume_pdf` | W (arquivo) | Owned; adaptação do idioma existente; se `is_ats_optimized`, exigir `ats_consent` (espelha a rota do painel) | `job_posting_id`, `ats_consent` (bool), `language` (opcional) | `{path, mime_type, bytes, generated_at}` | `ResumePdfService::download()` grava em `storage/app/private/mcp/pdf/` |

`language ∈ ResumeLanguage` (`pt-BR`, `en`); omitido = `pt-BR`. Cada adaptação é própria de um
(vaga, idioma); adaptar um idioma não substitui a adaptação do outro.

### 6.7 Candidaturas

| Tool | Tipo | Pré-condições | Entrada | Saída | Efeitos |
| ---- | ---- | ------------- | ------- | ----- | ------- |
| `start_application` | W | Owned | `job_posting_id` | `{application_id, status}` | `ApplicationTrackingService::start()` (idempotente) |
| `get_application` | R | Owned | `job_posting_id` | `{application, events[]}` | — |
| `list_applications` | R | — | `status`, `limit`, `offset` | lista | — |
| `record_application_update` | W | Owned | `application_id`, `status`, `title`, `notes`, `contact`, `occurred_at` | `{application_id, status, event_id}` | `ApplicationTrackingService::recordUpdate()` |

`status ∈ ApplicationStatus` (`planned`, `preparing`, `submitted`, `waiting`, `assessment`,
`interview`, `offer`, `completed`, `rejected`, `withdrawn`).

### 6.8 Busca, preferências e status

| Tool | Tipo | Pré-condições | Entrada | Saída | Efeitos |
| ---- | ---- | ------------- | ------- | ----- | ------- |
| `generate_search_url` | R | ≥1 keyword | `platform ∈ {linkedin,indeed}`, `mode ∈ {publications,jobs}` (default `jobs`), `keywords[]`, `excluded_terms[]`, `operator ∈ {all,any}`, `seniority`, `location`, `work_mode`, `published_within_days`, `easy_apply` | `{url}` | `SearchUrlGenerator::build()` (puro) |
| `get_screening_preferences` | R | — | — | `{preferences[], rejections[]}` | — |
| `save_screening_preferences` | W | — | `preferences[]`, `rejections[]` | `{preferences[], rejections[]}` | Linha única por usuário |
| `get_ai_operation` | R | Owned | `id` **ou** (`job_posting_id` + `type`) | `{id, type, status, attempts, timestamps, failure_code, target}` | Lê `AiOperation` (nunca dispara) |

## 7. Resources (read-only)

Recursos MCP apenas para injeção de contexto. Um MCP se conecta a `resources/read`; cada um
resolve o usuário configurado e nunca vaza dados de outro usuário.

| Resource | URI | Conteúdo |
| -------- | --- | -------- |
| `ResumeResource` | `jobora://resume` | Texto do currículo no idioma pedido (`language` opcional, padrão pt-BR) + `updated_at` |
| `ProfessionalCompetenciesResource` | `jobora://professional-competencies` | Conceitos + papel estratégico + `present`/`is_gap` |
| `MarketSnapshotResource` | `jobora://market-snapshot` | Total de vagas confirmadas, top conceitos e principais lacunas |
| `JobPostingResource` | `jobora://job-postings/{id}` (template) | Detalhe da vaga com itens e métodos |
| `ResumeAdaptationResource` | `jobora://resume-adaptations/{id}` (template) | Currículo adaptado no idioma pedido + resultados + ATS |

Formato: JSON em `Response::json(...)` (ou texto para o currículo). Resources não disparam IA
nem escrevem. Os resources de currículo e adaptação aceitam o idioma opcional (padrão pt-BR)
via argumento `language` ou query da URI (`jobora://resume?language=en`,
`jobora://resume-adaptations/1?language=en`).

## 8. Operações de IA assíncronas

O sistema já roda IA em fila (`jobs` + `AiOperation`). O MCP não bloqueia aguardando IA:

1. A tool de gatilho **enfileira** e retorna imediatamente
   (`{operation_id, status:'queued'}` quando `AiOperation`; `{job_posting_id, status:'pending'}`
   para análise/triagem).
2. O agente acompanha por leitura:
   - **Triagem**: `get_job_capture` → `triage_status`/`triage_result`/`triage_error`.
   - **Análise**: `get_job_posting` → `status`/`suggested_data`/`error_message`.
   - **Adaptação, ATS e competências**: `get_ai_operation` → `status`/`failure_code`.
3. **Worker ativo é pré-requisito.** Sem `php artisan dev`/`queue:work`, as operações ficam
   `queued` e expiram; o comando `ai-operations:expire` (agendado a cada minuto) marca
   `failed` com `worker_unavailable`.

Mapeamento gatilho → acompanhamento:

| Gatilho | Enfileira | Acompanhar com |
| ------- | --------- | -------------- |
| `triage_job_captures` | `TriageJobCaptures` | `get_job_capture` |
| `analyze_job_posting` / `promote_job_capture` | `AnalyzeJobPosting` | `get_job_posting` |
| `adapt_resume` | `AiOperation(resume_adaptation)` | `get_ai_operation` |
| `score_ats` | `AiOperation(ats_score)` | `get_ai_operation` |
| `classify_professional_competencies` | `AiOperation(strategic_competency_classification)` | `get_ai_operation` |
| `set_ats_optimization` | (sem IA — síncrono) | retorno imediato |

Deduplicação e limites já existem no `AiOperationService` (uma operação ativa por alvo, lock
por posting/usuário, expiração). O MCP não reimplementa nada disso.

## 9. PDF

`export_adapted_resume_pdf`:

1. Valida ownership, existência da adaptação e o `ats_consent` quando otimizado (espelha a
   rota `filament.admin.resume-pdf.download`).
2. Chama `ResumePdfService::download($adaptation)` — resolve a adaptação pelo `language`
   pedido (padrão pt-BR) — e grava o resultado em
   `storage/app/private/mcp/pdf/adapted-resume-{job_posting_id}.pdf` (versão PT-BR) ou
   `...-{job_posting_id}-en.pdf` (versão EN), sem persistir em banco.
3. Retorna `{ok:true, data:{path, mime_type:'application/pdf', bytes, generated_at}}`.

O binário **não** trafega pelo MCP; o Hermes lê o arquivo com suas ferramentas nativas.
Falha na geração → erro `internal` com log no canal `ai`, sem stack trace ao cliente.

## 10. Segurança

- **Fronteira de confiança:** stdio local. Não há socket de rede, porta, OAuth ou token;
  o acesso é limitado a quem já executa comandos na máquina.
- **Identidade fixa:** todas as queries filtram `user_id` do usuário configurado; nenhuma tool
  aceita `user_id`.
- **Autorização delegada:** Gates/Policies permanecem a autoridade (§4.5).
- **Confirmação explícita:** destrutivas/sobrescrita exigem `confirm: true` (§5.6).
- **Saída:** mensagens de erro sem stack trace, SQL, credenciais, `capture_token_hash`,
  conteúdo de `.env` ou dados de terceiros.
- **Logs:** operações de IA continuam logando no canal `ai` (sem API key). Erros inesperados
  das tools são logados no canal padrão com contexto mínimo.
- **Sem elevação:** o MCP nunca marca o usuário como admin/ATS automaticamente.

## 11. Integração com o Hermes

Bloco em `~/.hermes/config.yaml`:

```yaml
mcp_servers:
  jobora:
    command: "php"
    args: ["artisan", "mcp:start", "jobora"]
    cwd: "/home/enzo/Projects/vagas"
```

- `cwd` garante que o `artisan` encontre o app (Hermes suporta `cwd`, `env` e `command`/`args`).
- `JOBORA_MCP_USER` fica no `.env` do Jobora (recomendado) ou no bloco `env:` do Hermes.
- Podar tools no Hermes é opcional e reversível:
  ```yaml
  mcp_servers:
    jobora:
      # …
      tools:
        exclude: ["delete_job_posting", "delete_job_capture", "save_resume"]
  ```
- Validação: `hermes mcp list` (deve listar `jobora`), `hermes mcp test jobora`,
  `hermes mcp configure jobora` (seleção de tools). Para depurar sem Hermes:
  `php artisan mcp:inspector jobora`.

**Smoke manual:**

1. `php artisan migrate:fresh --seed` e `php artisan dev` (worker ativo).
2. `JOBORA_MCP_USER=admin@example.com` no `.env`.
3. `php artisan mcp:inspector jobora` → listar tools/resources e invocar `get_market_comparison`.
4. No Hermes: `hermes mcp test jobora` e um `capture_job` seguido de `triage_job_captures` +
   `get_job_capture` (confirmar transição de estado).
5. Fluxo de vaga: `create_job_posting` → `analyze_job_posting` → `get_job_posting`
   (`needs_review`) → `confirm_job_posting` → `get_market_comparison`.
6. ATS: `adapt_resume` → `get_ai_operation` → `get_resume_adaptation` →
   `export_adapted_resume_pdf` (conferir o arquivo no caminho retornado).

## 12. Testes

Padrão do projeto: **Pest**, `RefreshDatabase`, sem rede (agentes com fake, `Pdf::fake()`).

- Local: `tests/Feature/Mcp/`.
- Usar a API de teste do `laravel/mcp`:
  ```php
  JoboraServer::tool('list_job_postings', ['status' => 'confirmed'])
      ->assertOk()
      ->assertStructuredContent(fn ($content) => /* … */);
  ```
  (`assertOk`, `assertHasErrors`, `assertSee`, `assertStructuredContent`, resources via
  `JoboraServer::resource(...)`.)
- Configurar a identidade nos testes com `config(['jobora.mcp.user' => $user->email])`.
- Fakes: `JobExtractionAgent::fake([...])`, `JobTriageAgent::fake([...])`,
  `ResumeAdaptationAgent::fake([...])`, `AtsScorerAgent::fake([...])`,
  `StrategicCompetencyAgent::fake([...])`, `Pdf::fake()`.
- Cobertura mínima por domínio:
  - **Identidade/config:** `JOBORA_MCP_USER` ausente → `configuration`; inválido/desabilitado →
    `configuration`.
  - **Capturas:** `capture_job` idempotente (2ª chamada → `duplicate`); `triage_job_captures`
    respeita lote ≤50 e ownership; `promote_job_capture` nas pré-condições corretas;
    `delete_job_capture` exige `confirm`.
  - **Vagas:** fluxo pending → analyze (fake) → `needs_review` → `confirm_job_posting`
    (itens/`ItemSource`/`suggested_data` limpo) → `discard`; `analyze` rejeitada em `confirmed`;
    `delete` exige `confirm`.
  - **Currículo:** `save_resume` exige `confirm` e substitui a versão daquele idioma (uma por
    (usuário, idioma)); omitido o `language`, opera o pt-BR canônico, que reflete na comparação.
  - **Mercado:** cenário do PRD (2× Python, 1× React; currículo Python sem React → Python
    presente, React lacuna); `get_market_trends` com dados insuficientes.
  - **Competências:** `classify` enfileira com fake; `set_professional_competency` manual não é
    sobrescrito; `remove` exige `confirm`.
  - **Adaptação/ATS:** `adapt_resume` enfileira; `get_ai_operation` reflete estado;
    `set_ats_optimization` respeita Gate; `export_adapted_resume_pdf` com `Pdf::fake()` grava e
    retorna caminho; `ats_consent` obrigatório quando otimizado.
  - **Candidaturas:** `start_application` idempotente; `record_application_update` atualiza
    status + evento; lista filtra por `status`.
  - **Autorização:** usuário sem `can_use_ats_optimization` → `forbidden` nas tools de ATS;
    recurso de outro usuário → `not_found`.
  - **Resources:** cada resource retorna o conteúdo do usuário configurado e não vaza terceiros.
- Rodar o mínimo afetado: `php artisan test --compact --filter=Mcp` (e a suite ao final).

## 13. Plano de implementação

Cada etapa termina com testes verdes antes de avançar. Nenhuma migration nova.

| # | Tarefa | Entregável | Depende de |
| - | ------ | ---------- | ---------- |
| T001 | **Base MCP**: `composer require laravel/mcp:^0.9`; publicar `routes/ai.php`; `config/jobora.php` + `.env.example`; `McpUserResolver`; `JoboraTool` (base); `JoboraServer` vazio; registrar `Mcp::local('jobora', …)` | `mcp:start jobora` sobe; resolver valida identidade | — |
| T002 | **Capturas** (7 tools) | Captura idempotente + triagem assíncrona + promoção | T001 |
| T003 | **Vagas** (7 tools) + `JobPostingService::create/delete` | Fluxo create → analyze → review → confirm/discard/delete | T002 |
| T004 | **Currículo e preferências e busca** (`get/save_resume`, `get/save_screening_preferences`, `generate_search_url`) | Leitura/escrita singleton + URLs determinísticas | T001 |
| T005 | **Mercado** (4 tools) + resource `market_snapshot` | Frequências, pares, tendências, comparação/lacunas | T003 |
| T006 | **Competências** (4 tools) + resource `professional_competencies` | Classificação assíncrona + papéis manuais | T004, T005 |
| T007 | **Adaptação/ATS** (5 tools) + resource `resume_adaptation` + PDF em arquivo | Adequação assíncrona, ATS, exportação | T004, T005 |
| T008 | **Candidaturas** (4 tools) | Início e registro de eventos | T003 |
| T009 | **Resources restantes** (`resume`, `job_posting/{id}`) + `get_ai_operation` + estado async nos recursos | Contexto + acompanhamento | T005–T008 |
| T010 | **Integração Hermes**: bloco em `~/.hermes/config.yaml`, `mcp:inspector`, smoke manual (§11) e ajustes de descrição/annotations | Servidor utilizável pelo Hermes | T001–T009 |
| T011 | **Fechamento**: suite completa verde, polimento pt-BR das descrições, revisão de limites/erros e critérios de aceitação | Spec conformada | T010 |

Ordem de dependências:

```text
T001 → T002 → T003 ─┬→ T005 ─┬→ T006
        └─→ T004 ───┘        └→ T007
                          T003 → T008
T005..T008 → T009 → T010 → T011
```

## 14. Critérios de aceitação

1. **Boot:** `php artisan mcp:start jobora` inicia e lista as tools/resources; handle
   inexistente falha com mensagem do pacote.
2. **Identidade:** com `JOBORA_MCP_USER` válido, as tools operam o usuário certo; ausente/inválido
   → erro `configuration`; sem vazar dados de outro usuário (sempre `not_found`).
3. **Autorização:** tools de ATS falham com `forbidden` quando o usuário não tem o flag; nenhuma
   tool contorna Policies.
4. **Captura e triagem:** `capture_job` é idempotente; `triage_job_captures` respeita lote ≤50 e
   muda `triage_status`; `get_job_capture` reflete o resultado.
5. **Fluxo de vaga:** `create_job_posting` → `analyze_job_posting` (fake) → `needs_review` →
   `confirm_job_posting` persiste itens com `source` correto e limpa `suggested_data`;
   `discard` volta a `pending`; `delete` exige `confirm` e cascateia.
6. **Currículo:** `get_resume`/`save_resume` (com `confirm`) mantêm uma versão por
   (usuário, idioma) e refletem na comparação; `language` omitido opera o pt-BR.
7. **Mercado:** cenário do PRD (Python presente, React lacuna) correto; tendências com aviso de
   dados insuficientes quando aplicável.
8. **Competências:** classificação enfileira e é acompanhável; papéis manuais não são
   sobrescritos pela IA; remoção exige `confirm`.
9. **Adaptação/ATS:** `adapt_resume`/`score_ats` enfileiram e `get_ai_operation` reflete o
   estado; `set_ats_optimization` respeita Gate; integridade do currículo preservada (nenhum
   conceito inventado é aceito).
10. **PDF:** `export_adapted_resume_pdf` grava o arquivo e retorna caminho/mime/bytes; exige
    `ats_consent` quando otimizado; nenhum PDF persistido em banco.
11. **Candidaturas:** `start_application` idempotente; `record_application_update` cria evento e
    atualiza status; `list_applications` filtra por status.
12. **Contrato:** todas as tools seguem §5 (nomes, paginação default 20/máx 100, JSON, erros
    tipados, annotations); destrutivas exigem `confirm`.
13. **Resources:** os 5 resources respondem em `resources/read` com dados do usuário configurado.
14. **Testes:** `php artisan test --compact --filter=Mcp` verde, sem chamadas reais de IA ou PDF.
15. **Hermes:** `hermes mcp test jobora` bem-sucedido e smoke manual (§11) concluído.
16. **Sem regressão:** suite completa verde; API da extensão e painel inalterados.

## 15. Fora do escopo e evolução futura

- **HTTP/streamable HTTP**: possível evolução (§2 D2); exigirá token/OAuth e hardening de rede.
- **Multiusuário por chamada**: exigiria identidade por token e revisão de autorização.
- **Prompts MCP** guiados (“analisar vaga”, “preparar candidatura”).
- **`list_ai_operations`** e ferramentas de manutenção/observabilidade.
- **Restauração de capturas soft-deleted** (`restore_job_capture`).
- **Entrada de currículo em PDF/DOCX** — pendência do `TECHNICAL_PLAN.md` §17; independente do MCP.

## 16. Referências

- `PRD.md` — produto.
- `TECHNICAL_PLAN.md` — arquitetura e domínio.
- `IMPLEMENTATION_PLAN.md` — execução do MVP e F6.
- `laravel/mcp` v0.9.4 — `Server`, `Tool`, `Resource`, `Response`, annotations, `mcp:start`,
  `mcp:inspector`, API de teste.
- Hermes Agent — `~/.hermes/config.yaml` (`mcp_servers`), `hermes mcp list|test|configure`.
