# IMPLEMENTATION_PLAN — Análise do Mercado de Trabalho a partir de Vagas de Emprego

> Documento de execução derivado de `TECHNICAL_PLAN.md` (fonte técnica) e `PRD.md` (fonte de produto).
> O repositório já contém a maior parte do MVP implementada (verificado em 2026-08-13). Cada tarefa indica o estado atual: **verificar/aceitar** (validar o que existe contra critérios objetivos) ou **implementar** (criar o que falta). Não refazer trabalho existente. Ordem de execução segue o TECHNICAL_PLAN §16.
> Restrições fixas: Laravel 13 + Filament 5 + Laravel AI SDK (provider `opencode-go`, modelo `deepseek-v4-flash`); SQLite; single-user; vagas como fonte de dados (não gerenciador de vagas/candidaturas); sem scraping; sem Redis/filas/Elasticsearch; comparação agregada mercado × currículo; sem Ollama; sem chamadas HTTP próprias ao OpenCode Go (toda comunicação via Laravel AI SDK).

## 1. Estratégia de implementação

Construir o MVP em 11 fases seguindo a ordem de dependências do TECHNICAL_PLAN §16 (etapa 5 antes de 6–11; 7 antes de 8; 9 antes de 11; 10 antes de 11–13; 12/13 dependem de 10; 14 por último). Cada fase termina com seus testes verdes antes de avançar.

- **Fase A — Bootstrap (T001–T004):** projeto Laravel rodando, ambiente configurado, Filament instalado, Laravel AI SDK + provider OpenCode Go funcionando com smoke real.
- **Fase B — Banco e domínio (T005–T007):** migrations, Models, Enums, factories e seeder conforme modelagem do TP §4–5.
- **Fase C — Cadastro de vagas (T008):** Resource Filament List/Create com validação do TP §6.1.
- **Fase D — IA (T009–T013):** Agent, schema estruturado (contrato JSON no prompt), DTO validado, Service com tratamento de erros, ação "Analisar com IA", testes com fakes.
- **Fase E — Revisão humana (T014):** página de revisão com Confirmar/Descartar/Re-analisar e persistência de itens com `ItemSource`.
- **Fase F — Currículo (T015):** linha única substituível + página Filament.
- **Fase G — Análises (T016–T017):** normalização de conceitos + motor de frequências/rankings/combinações/série mensal.
- **Fase H — Comparação mercado × currículo (T018):** análise de presença no currículo + tabela aderência/lacunas.
- **Fase I — Dashboard (T019):** widgets de stats, top conceitos, lacunas e tendência.
- **Fase J — Análise temporal (T020):** série mensal + comparação de períodos + aviso de dados insuficientes.
- **Fase K — Testes e refinamentos (T021–T022):** suite completa verde + smoke manual ponta-a-ponta com chave real + Definition of Done.

Arquitetura alvo (TP §3): Models Eloquent + Enums string-backed + 1 DTO (`JobAnalysisResult`) + Services (lógica de negócio) + 1 Agent (`JobExtractionAgent`, único contato com o SDK) + Filament (1 Resource, 4 páginas custom, 4 widgets). Sem repository pattern, sem Actions classes, sem Jobs no MVP (fluxo síncrono). Nenhuma métrica materializada — tudo calculado sob demanda sobre vagas `confirmed`.

## 2. Tarefas

### Fase A — Bootstrap

#### T001 — Bootstrap do projeto Laravel 13

**Objetivo:** app Laravel 13 rodando localmente com a suite default verde.

**Contexto:** TP §16 etapa 1. O projeto já foi criado: `composer.json` requer `laravel/framework ^13.17` e PHP `^8.3`; `vendor/` instalado; `artisan` presente; `phpunit.xml` aponta sqlite `:memory:`; Pest 4.7 instalado. Estado: **implementado — verificar/aceitar**.

**Implementação:**
- Verificar: `php artisan --version` reporta 13.x; `composer install` conclui sem erros (se `vendor/` estiver íntegro, é no-op).
- Verificar: `php artisan serve` sobe e `GET /` responde 200 com a página `welcome` (rota default de `routes/web.php`).
- Verificar: `php artisan test` roda a suite default (ExampleTest) sem falhas.
- Corrigir qualquer falha encontrada antes de prosseguir.

**Dependências:** nenhuma.

**Critérios de conclusão:** `php artisan --version` = 13.x; `composer install` sem erro; `GET /` 200; suite default verde.

**Testes:** suite Pest default (ExampleTest) verde.

#### T002 — Configuração do ambiente (.env, SQLite, pt_BR)

**Objetivo:** ambiente local configurado e espelhado em `.env.example`, sem hardcode.

**Contexto:** TP §16 etapa 2 + §7 (variáveis de IA). Estado: **implementado — verificar/aceitar**. `.env` contém `APP_NAME=Vagas`, `APP_LOCALE=pt_BR`, `DB_CONNECTION=sqlite`, `AI_URL=https://opencode.ai/zen/go/v1`, `AI_API_KEY`, `AI_MODEL=deepseek-v4-flash`; `database/database.sqlite` existe; `.env.example` espelha as chaves (AI_API_KEY vazia); `.gitignore` não ignora `.env.example`.

**Implementação:**
- Conferir `.env` e `.env.example` lado a lado: todas as chaves de IA (`AI_URL`, `AI_API_KEY`, `AI_MODEL`) e de banco (`DB_CONNECTION=sqlite`) presentes em ambos; `.env` com valor real da key, `.env.example` com placeholder.
- Confirmar que `database/database.sqlite` existe e que `config/database.php` default usa sqlite (default do Laravel 13).
- Sem hardcode: as URLs/keys devem ser lidas via `env(...)` (já é o caso em `config/ai.php`).

**Dependências:** T001.

**Critérios de conclusão:** `.env` e `.env.example` espelhados nas chaves de IA/banco; `DB_CONNECTION=sqlite`; arquivo sqlite presente; nenhuma credencial hardcoded em código.

**Testes:** inspeção de arquivos; suite continua verde.

#### T003 — Instalação do Filament 5 e painel admin

**Objetivo:** painel `/admin` acessível com login.

**Contexto:** TP §16 etapa 3 + §14 (panel `admin`, código em inglês, UI pt-BR). Estado: **implementado — verificar/aceitar**. `filament/filament ^5.0` em `require-dev`; `app/Providers/Filament/AdminPanelProvider.php` define panel `admin` (path `admin`, login, discover de Resources/Pages/Widgets); `DatabaseSeeder` cria usuário `admin@example.com` / `password`.

**Implementação:**
- Verificar painel: com banco migrado e seedado (T005/T007), login em `/admin` com `admin@example.com`/`password` funciona (200 + dashboard).
- Se não houver usuário, criar via `php artisan db:seed` (DatabaseSeeder) — não inventar outro mecanismo.
- Nota (sem ação no MVP): Filament está em `require-dev`; mover para `require` só se houver deploy com `composer install --no-dev` — fora do escopo do MVP.

**Dependências:** T001, T002 (T005/T007 para o usuário existir; em execução estrita, T003 valida o painel após T007).

**Critérios de conclusão:** `/admin` responde 200 com formulário de login; autenticação com o usuário seed abre o dashboard.

**Testes:** verificação manual via navegador; testes Filament (T021) cobrem renderização das páginas autenticadas.

#### T004 — Instalação e configuração do Laravel AI SDK + provider OpenCode Go

**Objetivo:** chamada ao modelo via SDK funcionando (smoke com chave real), sem HTTP direto da aplicação.

**Contexto:** TP §16 etapa 4 + §7. Estado: **implementado — verificar/aceitar, com smoke a executar**. `laravel/ai ^0.10.3` em `require`; `config/ai.php` define default `opencode-go` e provider `opencode-go` (driver `openai-compatible`, `url` = env `AI_URL` default `https://opencode.ai/zen/go/v1`, `key` = env `AI_API_KEY`, `models.text.default` = env `AI_MODEL` default `deepseek-v4-flash`). O SDK anexa `/chat/completions` à base.

**Implementação:**
- Verificar `config/ai.php`: provider `opencode-go` presente e correto; default `opencode-go`.
- **Executar smoke manual** (parte que falta): com a chave real do `.env`, fazer um prompt simples via `Laravel\Ai\Facades\Ai::text(...)` (ou agente descartável) no `php artisan tinker`; conferir resposta não-vazia e latência razoável; registrar resultado (sucesso/erro) em `storage/logs/ai.log`.
- Confirmar que **nenhum** código da aplicação faz chamada HTTP ao OpenCode Go (única integração é o SDK, via agent — verificado em T009).
- Não usar o provider `ollama` (existe no config padrão do SDK, mas está inativo e fora do escopo).

**Dependências:** T001, T002.

**Critérios de conclusão:** smoke com chave real responde; `storage/logs/ai.log` registra a tentativa; nenhuma chamada HTTP própria ao provedor na aplicação.

**Testes:** smoke manual (fora de CI); sem chamadas reais em testes automatizados (fakes — T013).

### Fase B — Banco e domínio

#### T005 — Migrations de domínio (job_postings, job_posting_items, resumes)

**Objetivo:** schema persistente reproduzível conforme TP §4–5, com índices e constraints.

**Contexto:** TP §16 etapa 5. Estado: **implementado — verificar/aceitar**. Migrations existentes: `2026_08_12_000001_create_job_postings_table.php`, `2026_08_12_000002_create_job_posting_items_table.php`, `2026_08_12_000003_create_resumes_table.php` (além das default users/cache/jobs).

**Implementação:**
- Conferir `job_postings`: `description` TEXT NOT NULL; `source_url` TEXT nullable (sem UNIQUE); `collected_at` DATETIME NOT NULL; `status` enum default `pending`; `role`/`company` string(255) nullable; `suggested_data` json nullable; `error_message` TEXT nullable; timestamps; índices em `collected_at` e `status`.
- Conferir `job_posting_items`: `job_posting_id` FK → `job_postings.id` ON DELETE CASCADE; `category` enum com as 10 categorias; `name` string(100); `source` enum default `ai`; UNIQUE `(job_posting_id, category, name)`; índice composto `(category, name)`.
- Conferir `resumes`: `content` TEXT NOT NULL + timestamps.
- Executar `php artisan migrate:fresh` e confirmar sucesso; `php artisan migrate:fresh --seed` reproduzível.

**Dependências:** T001, T002.

**Critérios de conclusão:** `migrate:fresh --seed` executa sem erro e reproduz o schema acima (conferir via sqlite: colunas, enums/CHECK, índices, FK cascade, UNIQUE).

**Testes:** testes Feature usam `RefreshDatabase` (rodam as migrations — cobertura indireta); T021 valida a suite inteira.

#### T006 — Models e Enums do domínio

**Objetivo:** camada Eloquent e enums conforme TP §3–4.

**Contexto:** TP §16 etapa 5. Estado: **implementado — verificar/aceitar**. Models: `App\Models\JobPosting` (fillable completo, casts `collected_at`/`status`/`suggested_data`, relação `items()` hasMany), `App\Models\JobPostingItem` (fillable, casts `category`/`source`, `jobPosting()` belongsTo), `App\Models\Resume` (fillable `content`), `User` default. Enums: `JobPostingStatus` (pending|needs_review|confirmed|failed + label pt-BR), `ConceptCategory` (10 casos + `label()` + `fromSchemaKey()` + `all()`), `ItemSource` (ai|user).

**Implementação:**
- Conferir enums: valores exatos do TP §3 (string-backed); `ConceptCategory::fromSchemaKey` mapeia `technologies|languages|frameworks|databases|tools|cloud|methodologies|skills|requirements|experience` → categorias corretas.
- Conferir casts e relações dos 3 models (acima).
- Conferir factory binding: Models usam `HasFactory` e factories existem em `database/factories/`.

**Dependências:** T005.

**Critérios de conclusão:** enums com valores/casos corretos; casts ativos (status → enum, suggested_data → array, collected_at → datetime); relações `items()`/`jobPosting()` funcionam; `JobPosting::factory()` instancia.

**Testes:** testes Feature/Unit existentes (T021) exercitam enums, casts e relações; validar com `php artisan tinker` se necessário.

#### T007 — Factories e Seeder de desenvolvimento

**Objetivo:** dados de teste/dev reproduzíveis (TP §16 etapa 5).

**Contexto:** TP §5 + §16 etapa 1 (seed de 1 usuário). Estado: **implementado — verificar/aceitar**. `JobPostingFactory` (estados `confirmed`/`needsReview`/`failed`), `JobPostingItemFactory` (estado `category`), `ResumeFactory`, `UserFactory`; `DatabaseSeeder` cria 1 usuário admin.

**Implementação:**
- Conferir factories: `JobPostingFactory::definition()` preenche description/source_url/collected_at/status/role/company/suggested_data/error_message; estados `confirmed`/`needsReview`/`failed` corretos.
- Conferir `DatabaseSeeder`: cria `admin@example.com` (nome Admin, senha `password`).
- Executar `php artisan db:seed` (ou `migrate:fresh --seed`) e confirmar 1 usuário criado.

**Dependências:** T005, T006.

**Critérios de conclusão:** `db:seed` cria o usuário admin; factories instanciam modelos válidos (usados pelos testes Feature).

**Testes:** testes Feature consomem as factories (T021); nenhum teste dedicado necessário (cobertura indireta).

### Fase C — Cadastro de vagas

#### T008 — Resource Filament de vagas (List/Create)

**Objetivo:** cadastrar, listar e excluir vagas com descrição obrigatória (20–100k chars), link opcional, `collected_at = now` e `status = pending` (TP §6.1).

**Contexto:** TP §16 etapa 6 + §14 (Resource). Estado: **implementado — verificar/aceitar**. `JobPostingResource` (navigationLabel "Vagas", ícone briefcase), páginas `ListJobPostings` (CreateAction) e `CreateJobPosting` (seta `collected_at = now()` e `status = Pending` no mutate antes do create), `Schemas/JobPostingForm` (Textarea description required minLength 20 maxLength 100000 + TextInput source_url url() opcional), `Tables/JobPostingsTable` (colunas id/cargo/empresa/coletada em/status badge/itens; filtros status+período+categoria; ações de linha: analisar/revisar/excluir; defaultSort collected_at desc).

**Implementação:**
- Verificar o fluxo: criar vaga com descrição válida → status `pending`, `collected_at` = hoje, descrição preservada; criar sem link → salva normalmente; descrição < 20 chars → erro de validação.
- Verificar listagem: colunas, badges de status, filtros, ordenação por `collected_at` desc, ação excluir.
- Verificar que **não** existe página Edit/View genérica (descrição é imutável; correção = excluir e recadastrar) e que a revisão usa página própria (T014).

**Dependências:** T003, T005, T006.

**Critérios de conclusão:** cadastrar vaga (com e sem link) persiste com status `pending` e `collected_at` do dia; validação de descrição obrigatória/limites funciona; listagem com filtros e exclusão funciona.

**Testes:** `tests/Feature/JobPostingFlowTest.php` ("cria vaga como pending com collected_at = now e descrição preservada"); `tests/Feature/FilamentPagesTest.php` (listagem renderiza). Rodar e manter verdes.

### Fase D — IA

#### T009 — Agent de extração (JobExtractionAgent)

**Objetivo:** único ponto de contato com o Laravel AI SDK: provider `opencode-go`, modelo `deepseek-v4-flash`, timeout 120s, instruções pt-BR com contrato JSON no prompt (TP §7–8).

**Contexto:** TP §16 etapa 7. Estado: **implementado — verificar/aceitar**. `App\Agents\JobExtractionAgent` implementa `Laravel\Ai\Contracts\Agent` com trait `Promptable`: `provider()` → `opencode-go`; `model()` → lê da config (`config('ai.providers.opencode-go.models.text.default')`); `timeout()` → 120; `instructions()` descreve a extração em pt-BR, exige JSON cru sem markdown, pede nomes canônicos, proíbe categoria "other", e embute o schema JSON (role, company, technologies, languages, frameworks, databases, tools, cloud, methodologies, skills, requirements, experience — arrays opcionais, strings curtas, sem duplicatas).

**Implementação:**
- Conferir: provider/model/timeout corretos; **não** implementa `HasStructuredOutput` (provedor rejeita `response_format: json_schema` — TP §7); contrato JSON embutido nas instruções; sem HTTP próprio.
- Conferir que as instruções seguem o TP §8 (nomes canônicos estáveis, apenas o explícito na descrição, "responda APENAS com JSON válido").

**Dependências:** T004, T005, T006.

**Critérios de conclusão:** `provider()` retorna `opencode-go`; `model()` retorna o valor de `AI_MODEL`; `timeout()` = 120; instructions contêm o contrato JSON e as regras do TP §8; classe não implementa `HasStructuredOutput`.

**Testes:** uso via fakes do SDK nos testes de serviço (T013); nenhuma chamada real em CI.

#### T010 — DTO de resultado e validação estrutural (JobAnalysisResult)

**Objetivo:** resposta do modelo decodificada, validada e normalizada antes de qualquer persistência (TP §7–8).

**Contexto:** TP §16 etapa 7. Estado: **implementado — verificar/aceitar**. `App\DTO\JobAnalysisResult` (final, readonly): `fromStructured(array $data, ConceptNormalizer $normalizer)` valida tipos (lança `InvalidArgumentException` para tipos errados), limpa ruído (nomes vazios/longos, duplicados pós-normalização), aplica limites (`MAX_ITEMS = 100`, `MAX_NAME_LENGTH = 100`), trunca role/company em 255; `toSuggestedData()` devolve `{role, company, items: [{category, name}]}` para `suggested_data`.

**Implementação:**
- Conferir `fromStructured`: mapeamento das chaves do JSON via `ConceptCategory::fromSchemaKey` (categorias desconhecidas descartadas); normalização via `ConceptNormalizer`; limites de itens/char; erros estruturais viram exceção.
- Conferir `toSuggestedData`: shape exato usado pela página de revisão (T014).

**Dependências:** T006 (enums), T016 (ConceptNormalizer).

**Critérios de conclusão:** resposta válida → DTO com role/company/itens normalizados; tipos errados → `InvalidArgumentException`; duplicados pós-normalização colapsados; > 100 itens ou nomes > 100 chars tratados (descartados ou exceção conforme regra do TP §8).

**Testes:** `tests/Unit/JobAnalysisResultTest.php` (validar/estender se necessário): tipos errados lançam; ruído limpo; limites de itens/char.

#### T011 — Serviço de análise (JobAnalysisService)

**Objetivo:** orquestrar a extração: limites de descrição, chamada ao agent, parse/validação, transição `pending → needs_review | failed`, log no canal `ai` (TP §6.2–6.6, §7).

**Contexto:** TP §16 etapa 7. Estado: **implementado — verificar/aceitar**. `App\Services\JobAnalysisService` (constantes `MIN_DESCRIPTION_LENGTH = 20`, `MAX_DESCRIPTION_LENGTH = 100000`): `analyze(JobPosting)` lança `LogicException` para vagas `confirmed`; valida limites da descrição; `agent->prompt()`; `json_decode` (não-array → `InvalidArgumentException`); `JobAnalysisResult::fromStructured`; grava `needs_review` + role/company/`suggested_data`; catch `Throwable` → `failed` + `error_message` legível + `Log::channel('ai')` (sem API key); log de sucesso com job_posting_id/modelo/latência.

**Implementação:**
- Conferir o fluxo completo do método `analyze` (transições, `forceFill`, limpeza de `error_message` no sucesso e de `suggested_data` na falha).
- Conferir `config/logging.php` canal `ai` (driver single → `storage/logs/ai.log`).
- Conferir mensagens de erro legíveis em pt-BR e ausência de credenciais no log.

**Dependências:** T009, T010, T005, T006.

**Critérios de conclusão:** vaga `pending` → `needs_review` com `suggested_data` e role/company; JSON inválido/exceção/schema inválido → `failed` + `error_message` + log `ai`; `confirmed` rejeitada com `LogicException`; descrição fora dos limites → `failed`.

**Testes:** `tests/Feature/JobAnalysisServiceTest.php` (pending→needs_review; agente lança; saída estruturalmente inválida; JSON inválido; descrição fora dos limites; retry; nunca re-analisa confirmada).

#### T012 — Ação "Analisar com IA" (listagem)

**Objetivo:** disparar a extração pela UI, síncrona com indicador de loading, com feedback de sucesso/erro (TP §6.2, §14).

**Contexto:** TP §16 etapa 7. Estado: **implementado — verificar/aceitar**. `JobPostingsTable` expõe ações de linha (Analisar com IA, Revisar, Excluir) que usam `JobAnalysisService` + `Notification` do Filament.

**Implementação:**
- Verificar ação "Analisar com IA": chama `JobAnalysisService::analyze`, exibe loading durante a chamada síncrona, notifica sucesso (aguardando revisão) ou falha (status `failed` visível).
- Verificar ação "Revisar" → rota da página `ReviewJobPosting` (`/{record}/review`).

**Dependências:** T008, T011.

**Critérios de conclusão:** clicar "Analisar com IA" transiciona a vaga para `needs_review` ou `failed` com feedback visível; vaga `confirmed` não oferece re-análise.

**Testes:** `tests/Feature/FilamentPagesTest.php` ("ações da listagem mudam o estado da vaga").

#### T013 — Testes da IA com mock do SDK

**Objetivo:** cobrir caminhos ok/erro da integração sem chamadas reais em CI (TP §7, §15).

**Contexto:** TP §16 etapa 7. Estado: **implementado — verificar/aceitar**. Testes usam `JobExtractionAgent::fake([...])` com respostas fixas: válida, JSON inválido, fora do schema, exceção genérica.

**Implementação:**
- Conferir fixtures de resposta nos testes (`JobAnalysisServiceTest`, `JobPostingFlowTest`, `FilamentPagesTest`): válida / JSON malformado / fora do schema / tipos errados.
- Rodar `php artisan test --filter=JobAnalysisServiceTest` e manter verde.
- Garantir que nenhum teste faz chamada real (sem key em teste — `phpunit.xml` usa sqlite `:memory:` e não injeta chave).

**Dependências:** T010, T011.

**Critérios de conclusão:** os 4 caminhos de resposta (válida/inválida/fora do schema/exceção) têm cobertura e passam sem rede.

**Testes:** `JobAnalysisServiceTest`, `JobPostingFlowTest` (parte IA), `FilamentPagesTest` (fake na revisão).

### Fase E — Revisão humana

#### T014 — Página de revisão e confirmação (ReviewJobPosting)

**Objetivo:** revisar/corrigir a sugestão, confirmar (persiste itens com `ItemSource` e limpa `suggested_data`) ou descartar (volta a `pending`) (TP §6.3–6.5, §14).

**Contexto:** TP §16 etapa 8. Estado: **implementado — verificar/aceitar**. `app/Filament/Resources/JobPostings/Pages/ReviewJobPosting.php` (rota `/{record}/review`): `mount`/`fillForm` carregam `suggested_data`; form com role/company + repeater de itens (select de categoria + texto de nome + badge de origem ai/user); `confirm()` persiste role/company + itens (`source = ai` para inalterados, `user` para editados/adicionados — comparação via `ConceptNormalizer`), limpa `suggested_data`, status `confirmed`; `discard()` limpa `suggested_data` → `pending`; `analyzeAgain()` re-executa T011; view `resources/views/filament/resources/job-postings/pages/review-job-posting.blade.php` mostra descrição original read-only.

**Implementação:**
- Verificar página: descrição original read-only; campos role/company; repeater com categoria/nome; badge de origem por item.
- Verificar `confirm()`: persistência com `ItemSource` correto, `suggested_data` = null, status `confirmed`.
- Verificar `discard()`: `suggested_data` = null, status `pending` (permite re-análise).
- Verificar persistência da correção manual após recarregar a página.

**Dependências:** T008, T011, T010.

**Critérios de conclusão:** confirmar persiste itens com origem correta (ai inalterado / user editado+adicionado), limpa `suggested_data`, status `confirmed`; descartar volta a `pending`; correção manual persiste após reload; vaga `confirmed` entra nas análises (T017).

**Testes:** `tests/Feature/JobPostingFlowTest.php` ("analisa e confirma pela página de revisão", "persiste correção manual", "descarta sugestão", "vaga pendente/aguardando revisão não entra nas análises").

### Fase F — Currículo

#### T015 — Currículo (ResumeService + ResumePage)

**Objetivo:** cadastrar/atualizar o currículo em texto, linha única substituível, refletindo imediatamente na comparação (TP §10, §14).

**Contexto:** TP §16 etapa 9. Estado: **implementado — verificar/aceitar**. `App\Services\ResumeService` (`save()` = atualiza a linha existente — `first() ?? new Resume`; `current()`; `content()`). `App\Filament\Pages\ResumePage` (slug `resume`, navigationLabel "Currículo", textarea `content` required rows 20 autosize, `save()`, `lastUpdatedAt()` mostra data da última atualização, helperText explica substituição sem histórico).

**Implementação:**
- Verificar `ResumeService::save`: 2 envios → 1 linha, conteúdo = último envio.
- Verificar `ResumePage`: formulário carrega o texto atual no mount; salvar persiste; mostra data da última atualização.
- Verificar que o texto é armazenado como está (sem normalização persistida — a normalização é feita sob demanda por `ResumeAnalyzer`, T018).

**Dependências:** T003, T005, T006.

**Critérios de conclusão:** salvar currículo cria/atualiza linha única; novo envio substitui; data de atualização visível; conteúdo disponível via `ResumeService::content()`.

**Testes:** `tests/Feature/ResumeAndComparisonTest.php` ("salva o currículo em linha única e substitui"); `tests/Feature/FilamentPagesTest.php` ("renderiza a página do currículo e salva").

### Fase G — Análises

#### T016 — Normalização de conceitos (ConceptNormalizer + config/concepts.php)

**Objetivo:** nomes canônicos estáveis na base e matching consistente com o currículo (TP §9).

**Contexto:** TP §16 etapa 10 (normalização é pré-requisito de T010 e T018). Estado: **implementado — verificar/aceitar**. `config/concepts.php` define 25 conceitos canônicos (PostgreSQL, MySQL, SQL Server, MongoDB, Redis, PHP, Python, JavaScript, TypeScript, Node.js, React, Vue.js, Laravel, Symfony, Docker, Kubernetes, Git, AWS, Azure, Google Cloud, Linux, Agile, Scrum, Kanban, TDD) com `aliases` + `keywords`. `App\Services\ConceptNormalizer`: `normalize()` (trim + colapso whitespace + lowercase lookup; canônico desconhecido mantém nome limpo), `canonicalNames()`, `keywordsFor()`, `clean()`.

**Implementação:**
- Conferir `config/concepts.php`: cada entrada com `aliases` e `keywords`; nomes canônicos em Title Case.
- Conferir `ConceptNormalizer`: alias match exato sobre nome extraído; fallback = nome limpo; sem triggers no banco; relatórios consomem só nomes canônicos.
- Nota: crescimento do mapa é por edição de config (fora do MVP: tabela gerenciável).

**Dependências:** T005, T006.

**Critérios de conclusão:** aliases mapeiam para canônicos (postgres/pgsql → PostgreSQL; "laravel framework" → Laravel; "amazon web services" → AWS); nomes desconhecidos limpos (trim/collapse/lowercase); `keywordsFor` retorna keywords; usado por T010 e T018 (mesmo normalizador nas duas pontas).

**Testes:** `tests/Unit/ConceptNormalizerTest.php` (aliases, case/whitespace, multiword, canônico desconhecido).

#### T017 — Motor de análise de mercado (MarketAnalysisService)

**Objetivo:** frequências absolutas/relativas, rankings, combinações recorrentes e série mensal, tudo calculado sob demanda sobre vagas `confirmed` (TP §12).

**Contexto:** TP §16 etapa 10. Estado: **implementado — verificar/aceitar**. `App\Services\MarketAnalysisService`: `frequencies()` (GROUP BY `category, name`, `COUNT(DISTINCT job_posting_id)`, WHERE status confirmed, filtros opcionais categoria/período); `totalConfirmed()`; `relativeFrequencies()` (percent = absoluta / total de vagas confirmed no mesmo escopo, calculado em PHP); `top()` (top N); `pairsByCategory()` (self-join `a.job_posting_id = b.job_posting_id AND a.category = b.category AND a.name < b.name`, top 20); `monthlySeries()` (`strftime('%Y-%m', collected_at)` + JOIN job_postings); `months()`; `monthStart()`.

**Implementação:**
- Conferir a separação banco × aplicação: **banco (SQL via query builder)** — agregações `GROUP BY`/`COUNT(DISTINCT)`/self-join/`strftime` sobre os índices `(category, name)` e `collected_at`; **aplicação (PHP)** — cálculo de percentuais relativos, slicing de top N, composição de séries mensais e classificação de tendência (T020). Nenhuma tabela de métricas materializada.
- Conferir escopo: apenas vagas `status = confirmed` entram; `suggested_data` nunca entra nas agregações.
- Conferir combinações: pares na mesma categoria (MVP); cross-categoria fora do escopo.

**Dependências:** T005, T006, T014 (vagas confirmed).

**Critérios de conclusão:** com 3 vagas confirmed (2× Python, 1× React) → frequências Python 2 / React 1 e percentuais corretos (66.7% cada no cenário do teste); filtro por categoria funciona; ranking top N ordenado; combinações por self-join com contagem de vagas distintas; série mensal agrupada por `%Y-%m`.

**Testes:** `tests/Feature/AnalysisTest.php` (absolutas, relativas, filtro categoria, top N, combinações, UNIQUE por vaga); `tests/Feature/TemporalTest.php` (série mensal, meses distintos, filtro categoria).

### Fase H — Comparação mercado × currículo

#### T018 — Comparação e lacunas (ResumeAnalyzer + MarketComparisonService + MarketComparisonPage)

**Objetivo:** tabela item × frequência × presente/ausente no currículo, com lacunas priorizadas por frequência (TP §11, §14; PRD F4).

**Contexto:** TP §16 etapa 11. Estado: **implementado — verificar/aceitar**. `App\Services\ResumeAnalyzer`: `conceptsIn()` (normaliza o texto, verifica keywords por substring no texto normalizado, retorna conjunto de canônicos presentes — não persistido) e `hasConcept()`. `App\Services\MarketComparisonService`: `table()` cruza `relativeFrequencies()` com o conjunto do currículo → `{name, category, total, percent, present, is_gap}`; `gaps()` = itens `is_gap` (frequentes nas vagas, ausentes no currículo) ordenados por frequência desc. `App\Filament\Pages\MarketComparisonPage` (slug `market-comparison`, tabela com filtro de categoria, `hasResume()`, `getTotalConfirmed()`).

**Implementação:**
- Conferir `ResumeAnalyzer`: usa o **mesmo** `ConceptNormalizer` da extração (consistência obrigatória TP §9); keyword por substring no texto limpo; multiword (ex.: "Google Cloud").
- Conferir `MarketComparisonService`: frequência absoluta = `COUNT(DISTINCT job_posting_id)` por `(category, name)` entre vagas confirmed; relativa = absoluta/total confirmed (mesmo escopo); presença = conceito no conjunto do analisador.
- Conferir página: colunas Item | Categoria | Nº de vagas | % vagas | Currículo (Presente/Ausente); lacunas destacadas e ordenadas por frequência desc; filtro de categoria; aviso quando não há currículo.
- Enquadramento (TP §11): presença/ausência é atributo do item agregado — não é casamento binário vaga-a-vaga.

**Dependências:** T015 (currículo), T016 (normalizador), T017 (frequências).

**Critérios de conclusão:** cenário PRD — vagas com Python+React e currículo com Python sem React → Python Presente (2 vagas), React Ausente (1 vaga, lacuna), percentuais corretos; lacunas listam apenas itens frequentes e ausentes; tabela vazia sem vagas confirmed.

**Testes:** `tests/Feature/ResumeAndComparisonTest.php` (cenário PRD completo, lacunas, reflexo da atualização do currículo, tabela vazia); `tests/Feature/FilamentPagesTest.php` (página renderiza com dados do PRD).

### Fase I — Dashboard

#### T019 — Dashboard e widgets

**Objetivo:** visão agregada na home do painel: stats, top conceitos, lacunas e evolução mensal (TP §14).

**Contexto:** TP §16 etapa 12. Estado: **implementado — verificar/aceitar**. Widgets em `app/Filament/Widgets/`: `StatsOverviewWidget` (total vagas, confirmadas, aguardando revisão, falhas, conceitos distintos), `TopConceptsWidget` (TableWidget top 10 com filtro de categoria, COUNT DISTINCT por (category,name) entre confirmed), `ComparisonSummaryWidget` (top lacunas via `MarketComparisonService::gaps(10)`), `TrendsChartWidget` (LineChartWidget, top 6 itens por série mensal). `AdminPanelProvider` usa `discoverWidgets`.

**Implementação:**
- Verificar os 4 widgets renderizam com dados reais (criação de fixtures manual ou via factory).
- Conferir `TopConceptsWidget`: query restrita a vagas confirmed; chave de registro `(categoria|nome)` para linhas agregadas; top 10.
- Conferir `TrendsChartWidget`: eixo X = meses distintos com vagas confirmed; top 6 itens por total; rótulos pt-BR (M/y).
- Conferir Dashboard default do Filament exibe os widgets descobertos.

**Dependências:** T017, T018.

**Critérios de conclusão:** dashboard mostra stats corretos (contagens batem com a base), top conceitos ordenado, lacunas (itens ausentes do currículo), gráfico de linhas mensal com rótulos pt-BR.

**Testes:** `tests/Feature/FilamentPagesTest.php` ("widgets exibem dados corretos").

### Fase J — Análise temporal

#### T020 — Página de tendências (MarketTrendsPage)

**Objetivo:** evolução mensal das frequências + comparação entre períodos (3×3 meses, ±20%) + aviso de dados insuficientes (< 2 meses) (TP §13, §14; PRD F5).

**Contexto:** TP §16 etapa 13. Estado: **implementado — verificar/aceitar**. `App\Filament\Pages\MarketTrendsPage` (slug `market-trends`, navigationLabel "Tendências"): `hasEnoughData()` (≥ 2 meses distintos → sem aviso), `monthlyRows()` (top 12 itens por total, colunas = meses), `comparison()` (período A = 3 meses anteriores completos × B = últimos 3 completos; delta absoluto/relativo; classificação subiu/desceu/estável com threshold ±20%; item novo no período atual = "nova"), `comparisonLabels()` (pt-BR), view `filament/pages/market-trends.blade.php`.

**Implementação:**
- Conferir base temporal: `collected_at` indexado (T005); série via `strftime('%Y-%m', collected_at)` com JOIN em job_postings (T017).
- Conferir regras: aviso "Dados insuficientes" quando < 2 meses distintos; comparação 3×3 meses completos; threshold ±20% de delta relativo; classificação por item; item novo = "nova".
- Conferir UI: seletor de categoria + período; tabela mensal; gráfico de linhas (ChartWidget).
- Verificar texto do aviso em pt-BR e comportamento com dados de 1 mês vs 2+ meses.

**Dependências:** T017.

**Critérios de conclusão:** com vagas confirmed em 2+ meses, série mensal correta e sem aviso; com 1 mês, aviso "Dados insuficientes"; comparação classifica subiu/desceu/estável conforme threshold; labels de período pt-BR.

**Testes:** `tests/Feature/TemporalTest.php` (agrupamento mensal, meses distintos, filtro categoria, aviso com < 2 meses, sem aviso com 2 meses, classificação de tendência).

### Fase K — Testes e refinamentos

#### T021 — Suite completa e regressão do fluxo integral

**Objetivo:** suite Pest verde cobrindo o fluxo completo cadastrar → estruturar → revisar → confirmar → currículo → analisar → cruzar → visualizar (TP §15, §16 etapa 14).

**Contexto:** TP §16 etapa 14. Estado: **implementado — verificar/aceitar**. 9 arquivos de teste: Unit (`ConceptNormalizerTest`, `ResumeAnalyzerTest`, `JobAnalysisResultTest`) + Feature (`JobPostingFlowTest`, `JobAnalysisServiceTest`, `FilamentPagesTest`, `AnalysisTest`, `TemporalTest`, `ResumeAndComparisonTest`).

**Implementação:**
- Rodar `php artisan test` (phpunit.xml: sqlite `:memory:`, cache/session array, queue sync — sem rede, sem chave real).
- Corrigir qualquer falha (sem refatorar além do necessário para verde).
- Conferir cobertura do fluxo completo nos Feature tests: cadastro (T008) → análise com fake (T011/T012) → revisão/confirmação (T014) → currículo (T015) → análises (T017) → comparação/lacunas (T018) → tendências (T020).

**Dependências:** T008–T020.

**Critérios de conclusão:** `php artisan test` 100% verde (Unit + Feature) sem chamadas de rede.

**Testes:** toda a suite; se alguma lacuna de cobertura do fluxo for identificada, adicionar teste específico (mesmos padrões dos existentes: `uses(RefreshDatabase::class)`, fixtures via factory, fakes do agent).

#### T022 — Smoke manual ponta-a-ponta com chave real + Definition of Done

**Objetivo:** validar o fluxo real com o modelo `deepseek-v4-flash` (OpenCode Go) e conferir os critérios do MVP (TP §16 etapa 14; PRD §8).

**Contexto:** TP §16 etapa 14 — única etapa com chamada real ao provedor. Estado: **a executar**.

**Implementação:**
- `php artisan migrate:fresh --seed` (banco limpo + usuário admin).
- Login em `/admin`; cadastrar vaga real (descrição copiada de vaga real com tecnologias conhecidas); clicar "Analisar com IA" (síncrono, aguardar resposta — timeout 120s); conferir `needs_review` + sugestão na página de revisão.
- Revisar/corrigir um item; Confirmar; conferir status `confirmed`, `suggested_data` limpo, itens persistidos; recarregar e conferir persistência.
- Cadastrar currículo; conferir página Mercado × Currículo (frequências, presente/ausente, lacunas) e Dashboard (widgets).
- Cadastrar vaga em data passada (via factory/tinker) para conferir tendências com 2+ meses; verificar aviso de dados insuficientes quando houver < 2 meses.
- Conferir polimento pt-BR (labels das páginas/widgets) e limites/erros (descrição < 20 chars, link inválido, falha de IA → `failed` + mensagem legível + retry).
- Registrar smoke em `storage/logs/ai.log` (já feito pela aplicação).

**Dependências:** T021.

**Critérios de conclusão:** fluxo ponta-a-ponta real concluído com sucesso no navegador; `storage/logs/ai.log` registra análise real; lista de verificação do "Definition of Done do MVP" (abaixo) 100% verdadeira.

**Testes:** smoke manual (fora de CI); suite T021 permanece verde.

## 3. Definition of Done do MVP

Condições objetivas (derivadas do PRD §7–8) — todas devem ser verdadeiras para considerar o MVP concluído:

1. **Aplicação funcionando:** Laravel 13 roda localmente; painel Filament `/admin` acessível com login (1 usuário seed).
2. **Cadastro de vaga:** descrição obrigatória (20–100.000 chars) + link opcional; vaga sem link salva normalmente; `collected_at` = data do dia; status `pending`; descrição preservada integralmente e imutável após o cadastro (correção = excluir e recadastrar).
3. **Estruturação por IA:** ação "Analisar com IA" (síncrona) envia a descrição via Laravel AI SDK → provider `opencode-go` → `deepseek-v4-flash`; resposta validada pela aplicação (contrato JSON + DTO); sugestão gravada em `suggested_data`; status `needs_review`. Nenhuma chamada HTTP direta ao provedor.
4. **Revisão humana:** página de revisão mostra descrição original (read-only) + role/company/itens editáveis com origem ai/user; correção manual persiste após recarregar; Confirmar persiste os dados confirmados (itens com `ItemSource` correto, `suggested_data` apagado, status `confirmed`); Descartar volta a `pending`.
5. **Persistência dos dados confirmados:** apenas a versão revisada pelo usuário é armazenada como base; a sugestão do modelo não é mantida após a revisão.
6. **Vaga não confirmada fora das análises:** apenas vagas `confirmed` compõem frequências/combinações/tendências.
7. **Currículo:** cadastro e atualização em texto; novo envio substitui a versão anterior (linha única); atualização reflete na comparação imediatamente.
8. **Análises agregadas:** frequências absolutas e relativas por categoria, rankings, combinações recorrentes — cenário de aceitação: 3 vagas (2× Python, 1× React) → Python 2, React 1, percentuais corretos.
9. **Cruzamento mercado × currículo:** tabela Item | Categoria | Nº de vagas | % vagas | Currículo (Presente/Ausente); cenário PRD: Python presente, React ausente com frequências corretas.
10. **Lacunas:** itens frequentes nas vagas e ausentes do currículo, priorizados por frequência.
11. **Análise temporal básica:** série mensal por item; comparação 3×3 meses com classificação de tendência (±20%); aviso "dados insuficientes" com < 2 meses.
12. **Visualização no Filament:** Listagem/Cadastro de vagas, página de revisão, página do currículo, Mercado × Currículo, Tendências e Dashboard com widgets (stats, top conceitos, lacunas, gráfico mensal).
13. **Testes das funcionalidades críticas:** suite Pest verde (Unit + Feature) com fakes do SDK (sem chamadas reais em CI), cobrindo fluxo da vaga, análise, revisão, currículo, análises, comparação, temporal e páginas Filament.
14. **Smoke real concluído:** fluxo ponta-a-ponta com chave real validado no navegador (T022).

## 4. Ordem final

Dependências explícitas (tarefas sem relação podem ser executadas em paralelo):

```text
T001 Bootstrap Laravel
   ↓
T002 Configurar ambiente
   ↓
T003 Filament ──────────────┐
   ↓                        │ (T003 e T004 paralelos, ambos após T001/T002)
T004 AI SDK + provider ─────┘
   ↓
T005 Migrations
   ↓
T006 Models + Enums
   ↓
T007 Factories + Seeder
   ↓
T008 Cadastro de vagas
   ↓
T009 Agent → T010 DTO → T011 Service ──┬── T012 Ação listagem (paralelo a T013)
   │                                    └── T013 Testes com mock
   ↓
T014 Revisão humana
   ↓
T015 Currículo
   ↓
T016 Normalização (paralelo a T009–T011; pré-requisito de T010 e T018)
   ↓
T017 Análises
   ↓
T018 Comparação mercado × currículo
   ↓
T019 Dashboard ── T020 Análise temporal (paralelos, ambos após T017)
   ↓
T021 Suite completa / regressão
   ↓
T022 Smoke real + Definition of Done
```

| ID | Tarefa | Fase | Dependências | Resultado |
| -- | ------ | ---- | ------------ | --------- |
| T001 | Bootstrap do projeto Laravel 13 | A — Bootstrap | — | Laravel 13 rodando, suite default verde |
| T002 | Configuração do ambiente (.env, SQLite, pt_BR) | A — Bootstrap | T001 | Chaves de IA/banco no .env e .env.example, sem hardcode |
| T003 | Instalação do Filament 5 e painel admin | A — Bootstrap | T001, T002 | Painel /admin com login funcional |
| T004 | Laravel AI SDK + provider OpenCode Go + smoke | A — Bootstrap | T001, T002 | Chamada real ao deepseek-v4-flash via SDK funcionando |
| T005 | Migrations de domínio (job_postings, items, resumes) | B — Banco e domínio | T001, T002 | Schema com índices/constraints; migrate:fresh --seed ok |
| T006 | Models e Enums do domínio | B — Banco e domínio | T005 | Eloquent + enums com casts e relações corretos |
| T007 | Factories e Seeder de desenvolvimento | B — Banco e domínio | T005, T006 | Factories úteis + usuário admin seed |
| T008 | Resource Filament de vagas (List/Create) | C — Cadastro | T003, T005, T006 | Cadastrar/listar/excluir vagas (pending, collected_at=now) |
| T009 | Agent de extração (JobExtractionAgent) | D — IA | T004, T005, T006 | Agent provider opencode-go, modelo, timeout 120s, contrato JSON |
| T010 | DTO e validação estrutural (JobAnalysisResult) | D — IA | T006, T016 | Resposta validada/normalizada com limites do TP §8 |
| T011 | Serviço de análise (JobAnalysisService) | D — IA | T009, T010, T005, T006 | Transições pending→needs_review/failed + log ai |
| T012 | Ação "Analisar com IA" na listagem | D — IA | T008, T011 | Extração disparável pela UI com feedback |
| T013 | Testes da IA com mock do SDK | D — IA | T010, T011 | Caminhos ok/erro cobertos sem rede |
| T014 | Página de revisão e confirmação | E — Revisão | T008, T010, T011 | Confirmar/Descartar/Re-analisar com ItemSource |
| T015 | Currículo (ResumeService + ResumePage) | F — Currículo | T003, T005, T006 | Linha única substituível + página Filament |
| T016 | Normalização de conceitos | G — Análises | T005, T006 | Nomes canônicos consistentes (extração + currículo) |
| T017 | Motor de análise de mercado | G — Análises | T005, T006, T014 | Frequências, rankings, combinações, série mensal |
| T018 | Comparação mercado × currículo e lacunas | H — Comparação | T015, T016, T017 | Tabela item × freq × presente/ausente (cenário PRD) |
| T019 | Dashboard e widgets | I — Dashboard | T017, T018 | Stats, top conceitos, lacunas, gráfico mensal |
| T020 | Página de tendências (análise temporal) | J — Temporal | T017 | Série mensal + comparação 3×3 + aviso dados insuficientes |
| T021 | Suite completa e regressão do fluxo integral | K — Testes | T008–T020 | `php artisan test` verde |
| T022 | Smoke real ponta-a-ponta + Definition of Done | K — Testes | T021 | Fluxo real validado; DoD do MVP 100% |

## 5. Cobertura PRD × tarefas

| Funcionalidade PRD | Tarefas |
| ------------------ | ------- |
| F1 — Cadastro de descrição de vaga | T008 (cadastro com validação), T002 (ambiente) |
| F2 — Estruturação dos dados da vaga | T004 (provider), T009 (agent), T010 (schema/DTO), T011 (serviço/validação), T012 (ação), T013 (testes mock), T014 (revisão humana) |
| F3 — Cadastro e atualização do currículo | T015 |
| F4 — Comparação com o currículo (agregada) | T016 (normalização), T017 (frequências), T018 (comparação/lacunas), T019 (widget lacunas) |
| F5 — Análises do mercado | T017 (frequências/rankings/combinações), T019 (dashboard), T020 (evolução temporal) |
| Análises temporais (PRD §5) | T005 (índice collected_at), T017 (série mensal), T020 (comparação de períodos, aviso insuficiente) |
| MVP essencial (PRD §7) | T003–T020 (itens), T021–T022 (verificação final) |
| Critérios de aceitação (PRD §8) | T021 (testes), T022 (smoke + DoD) |
| Fluxo operacional completo | T022 (smoke ponta-a-ponta) |

Revisão interna: toda funcionalidade do PRD (F1–F5), análise temporal, comparação agregada e lacunas possuem tarefa(s) correspondente(s); nenhuma funcionalidade fora do escopo (scraping, gestão de candidaturas, multi-usuário, exportação, histórico de currículo) foi adicionada.
