# IMPLEMENTATION_PLAN — Análise do Mercado de Trabalho a partir de Vagas de Emprego

> Documento de execução derivado de `TECHNICAL_PLAN.md` (fonte técnica) e `PRD.md` (fonte de produto).
> O repositório já contém a maior parte do MVP implementada (verificado em 2026-08-13). Cada tarefa indica o estado atual: **verificar/aceitar** (validar o que existe contra critérios objetivos) ou **implementar** (criar o que falta). Não refazer trabalho existente. Ordem de execução segue o TECHNICAL_PLAN §16.
> Restrições fixas: Laravel 13 + Filament 5 + Laravel AI SDK (provider `opencode_zen`, modelo `mimo-v2.5-free`); SQLite; single-user; vagas como fonte de dados (não gerenciador de vagas/candidaturas); sem scraping; sem Redis/filas/Elasticsearch; comparação agregada mercado × currículo; sem Ollama; sem chamadas HTTP próprias ao OpenCode Zen (toda comunicação via Laravel AI SDK). F6 (adequação de currículo): IA via mesmo provider `opencode_zen`/`mimo-v2.5-free` — **Cloudflare não é provedor de IA**, atua apenas como driver de PDF (`spatie/laravel-pdf`); persistência de uma versão adaptada por (vaga, idioma) — PT-BR e EN — (sem histórico); currículo original nunca substituído; PDF não persistido (derivado sob demanda); sem DOCX no MVP; sem CRUD para a adaptação.

## 1. Estratégia de implementação

Construir o MVP em 11 fases seguindo a ordem de dependências do TECHNICAL_PLAN §16 (etapa 5 antes de 6–11; 7 antes de 8; 9 antes de 11; 10 antes de 11–13; 12/13 dependem de 10; 14 por último). Cada fase termina com seus testes verdes antes de avançar.

- **Fase A — Bootstrap (T001–T004):** projeto Laravel rodando, ambiente configurado, Filament instalado, Laravel AI SDK + provider OpenCode Zen funcionando com smoke real.
- **Fase B — Banco e domínio (T005–T007):** migrations, Models, Enums, factories e seeder conforme modelagem do TP §4–5.
- **Fase C — Cadastro de vagas (T008):** Resource Filament List/Create com validação do TP §6.1.
- **Fase D — IA (T009–T013):** Agent, schema estruturado (contrato JSON no prompt), DTO validado, Service com tratamento de erros, ação "Analisar com IA", testes com fakes.
- **Fase E — Revisão humana (T014):** página de revisão com Confirmar/Descartar/Re-analisar e persistência de itens com `ItemSource`.
- **Fase F — Currículo (T015):** versões por idioma (PT-BR canônico e EN derivado por tradução de IA) + página Filament.
- **Fase G — Análises (T016–T017):** normalização de conceitos + motor de frequências/rankings/combinações/série mensal.
- **Fase H — Comparação mercado × currículo (T018):** análise de presença no currículo + tabela aderência/lacunas.
- **Fase I — Dashboard (T019):** widgets de stats, top conceitos, lacunas e tendência.
- **Fase J — Análise temporal (T020):** série mensal + comparação de períodos + aviso de dados insuficientes.
- **Fase K — Testes e refinamentos (T021–T022):** suite completa verde + smoke manual ponta-a-ponta com chave real + Definition of Done.
- **Fase L — Adequação de currículo para ATS — F6 (T023–T032):** persistência, DTO, agent, validação de integridade, service, testes de domínio, PDF (`spatie/laravel-pdf` + driver `cloudflare`), página Filament não-CRUD, testes de integração e refinamentos — após o MVP existente (T001–T022), podendo rodar em paralelo com Fases I–J. T033 (entrada PDF/DOCX) fica **bloqueada** pela decisão de extração de texto (TP §17).

Arquitetura alvo (TP §3): Models Eloquent + Enums string-backed + 1 DTO (`JobAnalysisResult`) + Services (lógica de negócio) + 1 Agent (`JobExtractionAgent`, único contato com o SDK) + Filament (1 Resource, 4 páginas custom, 4 widgets). Sem repository pattern, sem Actions classes, sem Jobs no MVP (fluxo síncrono). Nenhuma métrica materializada — tudo calculado sob demanda sobre vagas `confirmed`. Com a F6: +1 Model (`ResumeAdaptation`), +1 DTO (`ResumeAdaptationResult`), +1 Agent (`ResumeAdaptationAgent`, mesmo provider `opencode_zen`), +2 Services (`ResumeAdaptationService`, `ResumePdfService`), +1 página custom (`ResumeAdaptationPage`) e +1 pacote (`spatie/laravel-pdf` com driver `cloudflare`).

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

**Contexto:** TP §16 etapa 2 + §7 (variáveis de IA). Estado: **implementado — verificar/aceitar**. `.env` contém `APP_NAME=Jobora`, `APP_LOCALE=pt_BR`, `DB_CONNECTION=sqlite`, `OPENCODE_ZEN_API_KEY`, `OPENCODE_ZEN_MODEL=mimo-v2.5-free`; `database/database.sqlite` existe; `.env.example` espelha as chaves (OPENCODE_ZEN_API_KEY vazia); `.gitignore` não ignora `.env.example`.

**Implementação:**
- Conferir `.env` e `.env.example` lado a lado: todas as chaves de IA (`OPENCODE_ZEN_API_KEY`, `OPENCODE_ZEN_MODEL`) e de banco (`DB_CONNECTION=sqlite`) presentes em ambos; `.env` com valor real da key, `.env.example` com placeholder.
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

#### T004 — Instalação e configuração do Laravel AI SDK + provider OpenCode Zen

**Objetivo:** chamada ao modelo via SDK funcionando (smoke com chave real), sem HTTP direto da aplicação.

**Contexto:** TP §16 etapa 4 + §7. Estado: **implementado — verificar/aceitar, com smoke a executar**. `laravel/ai ^0.10.3` em `require`; `config/ai.php` define default `opencode_zen` e provider `opencode_zen` (driver `openai-compatible`, `key` = env `OPENCODE_ZEN_API_KEY`, `models.text.default` = env `OPENCODE_ZEN_MODEL` default `mimo-v2.5-free`). O SDK usa o driver `openai-compatible` com a base `https://opencode.ai/zen/v1` (configurada via `OPENCODE_ZEN_BASE_URL`; o SDK acrescenta `/chat/completions`).

**Implementação:**
- Verificar `config/ai.php`: provider `opencode_zen` presente e correto; default `opencode_zen`.
- **Executar smoke manual** (parte que falta): com a chave real do `.env`, fazer um prompt simples via `Laravel\Ai\Facades\Ai::text(...)` (ou agente descartável) no `php artisan tinker`; conferir resposta não-vazia e latência razoável; registrar resultado (sucesso/erro) em `storage/logs/ai.log`.
- Confirmar que **nenhum** código da aplicação faz chamada HTTP ao OpenCode Zen (única integração é o SDK, via agent — verificado em T009).
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

**Objetivo:** único ponto de contato com o Laravel AI SDK: provider `opencode_zen`, modelo `mimo-v2.5-free`, timeout 120s, instruções pt-BR com contrato JSON no prompt (TP §7–8).

**Contexto:** TP §16 etapa 7. Estado: **implementado — verificar/aceitar**. `App\Agents\JobExtractionAgent` implementa `Laravel\Ai\Contracts\Agent` com trait `Promptable`: `provider()` → `opencode_zen`; `model()` → lê da config (`config('ai.providers.opencode_zen.models.text.default')`); `timeout()` → 120; `instructions()` descreve a extração em pt-BR, exige JSON cru sem markdown, pede nomes canônicos, proíbe categoria "other", e embute o schema JSON (role, company, technologies, languages, frameworks, databases, tools, cloud, methodologies, skills, requirements, experience — arrays opcionais, strings curtas, sem duplicatas).

**Implementação:**
- Conferir: provider/model/timeout corretos; **não** implementa `HasStructuredOutput` (o contrato JSON é embutido nas instruções e a resposta é validada na aplicação pelos DTOs, sem `response_format: json_schema` nativo — TP §7); contrato JSON embutido nas instruções; sem HTTP próprio.
- Conferir que as instruções seguem o TP §8 (nomes canônicos estáveis, apenas o explícito na descrição, "responda APENAS com JSON válido").

**Dependências:** T004, T005, T006.

**Critérios de conclusão:** `provider()` retorna `opencode_zen`; `model()` retorna o valor de `OPENCODE_ZEN_MODEL`; `timeout()` = 120; instructions contêm o contrato JSON e as regras do TP §8; classe não implementa `HasStructuredOutput`.

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

**Objetivo:** cadastrar/atualizar o currículo em versões por idioma (uma por (usuário, idioma); PT-BR canônico e EN derivado por tradução de IA), refletindo imediatamente na comparação (TP §10, §14).

**Contexto:** TP §16 etapa 9. Estado: **implementado — verificar/aceitar**. `App\Services\ResumeService` (`save($user, $content, $language)` = atualiza a linha daquele idioma; `current($user, $language)`; `content($user, $language)`; `saveTranslation()` grava a versão EN com o `source_fingerprint` do PT-BR). `App\Filament\Pages\ResumePage` (slug `resume`, navigationLabel "Currículo", abas PT-BR | EN, textarea `content` required rows 20 autosize, `save()` grava a versão da aba, `lastUpdatedAt()` mostra data da última atualização, botão de gerar/regenerar a versão EN com confirmação e aviso de drift).

**Implementação:**
- Verificar `ResumeService::save`: 2 envios no mesmo idioma → 1 linha, conteúdo = último envio; idiomas distintos coexistem.
- Verificar `ResumePage`: formulário carrega o texto do idioma ativo no mount; salvar persiste aquela versão; mostra data da última atualização; geração da versão EN substitui a anterior (com confirmação) e sinaliza drift quando o PT-BR muda depois.
- Verificar que o texto é armazenado como está (sem normalização persistida — a normalização é feita sob demanda por `ResumeAnalyzer`, T018).

**Dependências:** T003, T005, T006.

**Critérios de conclusão:** salvar currículo cria/atualiza a versão daquele idioma; novo envio substitui a versão; PT-BR e EN coexistem; data de atualização visível; conteúdo disponível via `ResumeService::content($user, $language)`.

**Testes:** `tests/Feature/ResumeAndComparisonTest.php` ("currículo por idioma"); `tests/Feature/FilamentPagesTest.php` ("renderiza a página do currículo e salva").

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

**Objetivo:** validar o fluxo real com o modelo `mimo-v2.5-free` (OpenCode Zen) e conferir os critérios do MVP (TP §16 etapa 14; PRD §8).

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

### Fase L — Adequação de currículo para ATS (F6)

**Objetivo da fase:** implementar a F6 ponta a ponta conforme TP etapas 15–17: persistência de uma versão adaptada por (vaga, idioma), agente de adequação (mesmo provider `opencode_zen`/`mimo-v2.5-free`), validação de integridade em 2 camadas, página de resultado não-CRUD e exportação PDF via `spatie/laravel-pdf` com driver `cloudflare` (Cloudflare Browser Run). Cloudflare **não** é provedor de IA. Nenhum teste depende de chamadas reais (fakes do Agent e `Pdf::fake()`).

**Decisões de implementação registradas:** o download do PDF é uma **rota GET autenticada do painel** (`filament.admin.resume-pdf.download`), não uma ação Livewire — retornos de arquivo de ações são serializados no snapshot JSON e bytes binários quebram o encode (erro "Malformed UTF-8"). A autorização do Resource/ações/páginas/rota é explícita via `App\Policies\JobPostingPolicy` (single-user: todos os métodos permitem; a fronteira fica testável). `AnalyzeJobPosting` implementa `ShouldQueueAfterCommit` (o job só é disparado após o commit da transação). A análise em segundo plano exige worker ativo (`php artisan dev` ou `queue:work`).

**Contexto geral:** TP §3–§8, §14, §15, §16 etapas 15–17; PRD F6, §4 (dados), §6 (fluxo), §7 (MVP), §8 (critérios de aceitação). A F6 depende do MVP existente: domínio de vagas (T005–T007), estruturação (T008–T014), currículo (T015), normalização (T016) e comparação mercado × currículo (T018).

#### T023 — Persistência da adequação (migration + Model `ResumeAdaptation`)

**Objetivo:** tabela `resume_adaptations` com constraint por (vaga, idioma) (uma versão por idioma, sem histórico temporal) e Model Eloquent, sem qualquer alteração no currículo original (TP §4–5, §16 etapa 15).

**Contexto:** TP §4 (entidade `ResumeAdaptation`), §5 (migration 5), §16 etapa 15. Estado: **implementado — verificar/aceitar**.

**Implementação:**
- Migration `resume_adaptations`: `id` BIGINT PK; `job_posting_id` BIGINT NOT NULL FK → `job_postings.id` ON DELETE CASCADE; `language` TEXT NOT NULL (default `pt-BR`); **UNIQUE** (`job_posting_id`, `language`) (garante 1 adaptação por (vaga, idioma)); `adapted_content` TEXT NOT NULL; `changes`, `covered_keywords`, `missing_requirements`, `alerts` TEXT NULL (JSON); `created_at`/`updated_at`.
- Model `App\Models\ResumeAdaptation`: fillable completo; casts `changes`/`covered_keywords`/`missing_requirements`/`alerts` → array; relação `jobPosting()` belongsTo.
- Sem tabela de histórico: regenerar = atualizar a mesma linha (UPSERT é responsabilidade do service, T027).
- Nenhuma mudança em `resumes` (o original nunca é substituído).

**Dependências:** T005 (schema base), T006 (models/enums).

**Critérios de conclusão:** `php artisan migrate` cria a tabela com UNIQUE em `job_posting_id` e FK com cascade; Model com casts e relação funcionando; `resumes` inalterada.

**Testes:** cobertura indireta via T028 (Feature com `RefreshDatabase`); conferir schema via `php artisan migrate:status`/tinker.

#### T024 — DTO `ResumeAdaptationResult`

**Objetivo:** representar e validar estruturalmente a resposta da IA da adequação — currículo adaptado, alterações, palavras-chave/requisitos contemplados, requisitos ausentes e alertas de integridade (TP §8).

**Contexto:** TP §3 (DTO), §8 (schema F6), §16 etapa 15. Estado: **implementado — verificar/aceitar**.

**Implementação:**
- `App\DTO\ResumeAdaptationResult` (final, readonly): `fromStructured(array $data)` — valida tipos (lança `InvalidArgumentException` para tipos errados), limpa strings (trim, colapso de whitespace) e aplica limites: `adapted_resume` ≤ 20.000 chars; listas ≤ 50 itens; itens ≤ 200 chars.
- Campos: `adaptedResume`, `changes`, `coveredKeywords`, `missingRequirements`, `alerts`.
- Conteúdo estruturado, **nunca HTML**: a IA não retorna HTML e o DTO não o aceita.

**Dependências:** nenhuma (pode rodar em paralelo com T023).

**Critérios de conclusão:** resposta válida → DTO preenchido; tipos errados/limites excedidos → exceção; campos limpos.

**Testes:** `tests/Unit/ResumeAdaptationResultTest` (T028).

#### T025 — Agent `ResumeAdaptationAgent`

**Objetivo:** único ponto de contato com o Laravel AI SDK para a adequação: provider `opencode_zen`, modelo `mimo-v2.5-free`, timeout 120s, instruções pt-BR com o contrato JSON da F6 e a regra de integridade (TP §7–8).

**Contexto:** TP §3 (Agents), §7 (integração), §8 (schema F6), §16 etapa 15. Estado: **implementado — verificar/aceitar**.

**Implementação:**
- `App\Agents\ResumeAdaptationAgent` implementa `Laravel\Ai\Contracts\Agent` com trait `Promptable`: `provider()` → `opencode_zen`; `model()` → `config('ai.providers.opencode_zen.models.text.default')` (`OPENCODE_ZEN_MODEL`); `timeout()` → 120.
- `instructions()` em pt-BR: analisar a vaga em relação ao currículo (terminologia, palavras-chave, organização, destaque de experiências relevantes, priorização de competências); **reformular/organizar/reordenar apenas informações existentes — nunca inventar** (experiências, tecnologias, cargos, certificações, resultados, projetos, responsabilidades, formação); requisitos ausentes vão para `missing_requirements`; alertas quando uma adaptação exigiria inventar; embute o contrato JSON da F6 (TP §8); exige JSON cru, sem markdown e **sem HTML**.
- **Não** implementa `HasStructuredOutput` (contrato JSON embutido nas instruções e validado na aplicação pelos DTOs, sem `response_format: json_schema` nativo — TP §7).
- Sem chamadas HTTP próprias; nenhuma referência à Cloudflare na parte de IA.

**Dependências:** T004 (SDK + provider), T024 (contrato).

**Critérios de conclusão:** provider/model/timeout corretos; instructions com regra de integridade + schema F6; sem `HasStructuredOutput`; sem referência a Cloudflare/HTML.

**Testes:** uso via fakes (T028/T031); nenhuma chamada real.

#### T026 — Validação de integridade (2 camadas)

**Objetivo:** garantir a regra do PRD/TP: todo conceito canônico no currículo adaptado deve existir no currículo original; conceito novo introduzido pela IA ⇒ resposta rejeitada (TP §8).

**Contexto:** TP §8 (validação de integridade), §9 (reuso do normalizador), §16 etapa 15. Estado: **implementado — verificar/aceitar**.

**Implementação:**
- Camada (a) — estrutural: regras do `ResumeAdaptationResult::fromStructured` (T024): tipos e limites.
- Camada (b) — de integridade (no `ResumeAdaptationService`, reutilizável e testável):
  - conceitos do `adapted_resume` (via `ResumeAnalyzer`/`ConceptNormalizer`) ⊆ conceitos do currículo original — conceito novo ⇒ rejeição com mensagem de integridade;
  - `covered_keywords` ⊆ conceitos presentes no currículo original;
  - `missing_requirements` consistente com os itens da vaga ausentes do currículo (itens `confirmed` da vaga × `ResumeAnalyzer`);
  - sem camada extra de infraestrutura: reusa `ConceptNormalizer` (T016) e `ResumeAnalyzer` (T018).

**Dependências:** T016 (normalizador), T018 (`ResumeAnalyzer`), T024 (DTO).

**Critérios de conclusão:** adaptado com conceito inventado ⇒ rejeição; `covered_keywords` fora do currículo ⇒ rejeição; `missing_requirements` inconsistente ⇒ rejeição; mensagens legíveis em pt-BR.

**Testes:** Unit/Feature em T028 (respostas fake: válida, conceito inventado, keywords fora, ausentes inconsistentes).

#### T027 — Service `ResumeAdaptationService`

**Objetivo:** orquestrar a adequação ponta a ponta (coleta → agent → validação → UPSERT), sem lógica no Filament (TP §3, §6 F6, §16 etapa 15).

**Contexto:** TP §3 (Services), §6 (fluxo F6), §16 etapa 15. Estado: **implementado — verificar/aceitar**.

**Implementação:**
- `App\Services\ResumeAdaptationService::adapt(JobPosting $posting)`:
  1. pré-condições: vaga `confirmed`; currículo cadastrado (`ResumeService::content()`) — senão, exceção com mensagem clara;
  2. coleta: currículo original, descrição original, itens estruturados (`JobPostingItem` da vaga) e posicionamento mercado × currículo da vaga (itens da vaga × presença no currículo via `ResumeAnalyzer`);
  3. `ResumeAdaptationAgent->prompt(...)` → `json_decode` (não-array ⇒ `InvalidArgumentException`);
  4. `ResumeAdaptationResult::fromStructured` + validação de integridade (T026) — falha ⇒ exceção, nada persistido;
  5. UPSERT: `ResumeAdaptation::updateOrCreate(['job_posting_id' => ...], [...])` — regenerar substitui a última; nunca toca em `Resume`;
  6. retorna a adaptação persistida.
- Erros: `try/catch` → `Log::channel('ai')` (sem API key) + exceção legível para a UI.
- Síncrono, sem filas (mesmo padrão da extração).

**Dependências:** T023 (model), T024 (DTO), T025 (agent), T026 (integridade), T015 (`ResumeService`), T018 (`ResumeAnalyzer`), T014 (vaga confirmed).

**Critérios de conclusão:** fluxo completo gera e persiste a adaptação daquele (vaga, idioma); regenerar substitui a versão daquele idioma; falha não persiste nada; vaga não confirmada ou sem currículo daquele idioma ⇒ exceção clara; `resumes` intocada.

**Testes:** T028 (Feature com fakes) + T031.

#### T028 — Testes do domínio F6 (DTO, integridade, UPSERT, original intocado)

**Objetivo:** cobrir DTO, validação de integridade, persistência UPSERT e a garantia de que o currículo original nunca é alterado (TP §15).

**Contexto:** TP §15 (F6 — adequação). Estado: **implementado — verificar/aceitar**.

**Implementação:**
- `tests/Unit/ResumeAdaptationResultTest`: tipos errados lançam; limites (itens/chars); campos limpos; HTML rejeitado.
- `tests/Feature/ResumeAdaptationFlowTest` (parte domínio, com `ResumeAdaptationAgent::fake([...])`):
  - resposta válida → adaptação persistida (1 linha por (vaga, idioma), com `adapted_content` e listas JSON);
  - regenerar → mesma linha, conteúdo novo (UPSERT);
  - resposta com conceito inventado (fora do currículo original) ⇒ rejeição, nada persistido;
  - vaga não `confirmed` ou sem currículo ⇒ exceção, nada persistido;
  - **currículo original (`resumes`) inalterado** após qualquer adequação;
  - JSON inválido / fora do schema / exceção genérica ⇒ erro sem persistência.
- Fakes do SDK — sem chamadas reais (phpunit.xml: sqlite `:memory:`, sem chave).

**Dependências:** T023, T024, T026, T027.

**Critérios de conclusão:** cenários acima verdes, sem rede.

**Testes:** os próprios testes.

#### T029 — PDF: `spatie/laravel-pdf` + driver `cloudflare` + `ResumePdfService` + Blade

**Objetivo:** exportação do currículo adaptado em PDF (formato do MVP) via `spatie/laravel-pdf` com driver `cloudflare` (Cloudflare Browser Run), a partir de HTML/Blade controlado pela aplicação; PDF derivado sob demanda, não persistido (TP §2, §10, §14, §16 etapa 16).

**Contexto:** TP §2 (stack), §14 (PDF — template Blade), §16 etapa 16; PRD F6 (exportação PDF no MVP; DOCX fora do MVP). Estado: **implementado — verificar/aceitar**.

**Implementação:**
- Instalar/configurar `spatie/laravel-pdf` (composer) com driver `cloudflare` → Cloudflare Browser Run API; credenciais via env (sem hardcode — nomes definidos na configuração do driver; conferir pendência no TP §17).
- `App\Services\ResumePdfService::download(ResumeAdaptation $adaptation)`: renderiza o Blade de impressão com o `adapted_content` + dados da vaga → gera o PDF via `spatie/laravel-pdf` → resposta de download.
- Template `resources/views/pdf/adapted-resume.blade.php`: próprio para impressão — A4, sem cores de fundo, tipografia legível, texto selecionável (leitura por ATS); conteúdo textual do adaptado, não HTML da IA.
- **Não** persistir PDFs (gerar sob demanda). **Não** criar DOCX.

**Dependências:** T027 (dados da adaptação); T002 (env para credenciais).

**Critérios de conclusão:** `Pdf::fake()` permite testar sem rede; Blade renderiza o conteúdo correto; download responde com PDF; nenhum PDF persistido; sem DOCX.

**Testes:** `Pdf::fake()` em T031 (`ResumePdfTest`); smoke real com credencial Cloudflare em T032.

#### T030 — Filament `ResumeAdaptationPage` (não-CRUD)

**Objetivo:** página de resultado da adequação acessível a partir da vaga, sem CRUD genérico (TP §14, §16 etapa 16; PRD F6).

**Contexto:** TP §14 (F6 — `ResumeAdaptationPage`). Estado: **implementado — verificar/aceitar**.

**Implementação:**
- Ação "Adequar currículo" na listagem (`JobPostingsTable`) — somente vaga `confirmed` com currículo cadastrado; síncrona com loading; navega para a página.
- `App\Filament\Resources\JobPostings\Pages\ResumeAdaptationPage` (rota `/{record}/adapt`):
  - vaga (cargo/empresa) + currículo original (fonte) + currículo adaptado;
  - painéis: alterações realizadas, palavras-chave/requisitos contemplados, requisitos ausentes, alertas de integridade;
  - ações: "Adequar novamente" (chama `ResumeAdaptationService`, atualiza a página), "Copiar" (texto do adaptado), "Baixar PDF" (`ResumePdfService` — T029);
  - avisos: sem currículo cadastrado ou vaga não confirmada ⇒ ação indisponível com mensagem.
- Sem páginas de listagem/edição genérica para adaptações; sem CRUD.

**Dependências:** T003 (Filament), T008 (listagem), T027 (service), T029 (PDF).

**Critérios de conclusão:** ação na listagem disponível apenas nas condições corretas; página renderiza os 5 resultados; "Adequar novamente" regenera; "Copiar" e "Baixar PDF" funcionam; sem CRUD.

**Testes:** T031 (Filament — renderização e ações).

#### T031 — Testes de integração F6 (fluxo completo + PDF + Filament)

**Objetivo:** validar o fluxo ponta a ponta da F6 sem chamadas reais: vaga → adequação → persistência → página → PDF (TP §15, §16 etapa 17).

**Contexto:** TP §15 (F6), §16 etapa 17. Estado: **implementado — verificar/aceitar**.

**Implementação:**
- `tests/Feature/ResumePdfTest` com `Pdf::fake()`: `ResumePdfService` gera o PDF a partir do Blade com o conteúdo correto; sem chamadas ao Cloudflare.
- `tests/Feature/FilamentPagesTest` (F6): página de resultado renderiza com dados do fake; ação "Adequar currículo" na listagem leva à página; "Adequar novamente" atualiza; "Baixar PDF" responde com PDF fake; "Copiar" disponível.
- Fluxo completo: vaga confirmed + currículo → adaptação persistida → página exibe os 5 resultados → PDF gerado.
- Nenhuma chamada real a OpenCode Zen ou Cloudflare.

**Dependências:** T028, T029, T030.

**Critérios de conclusão:** fluxo completo verde com fakes; `php artisan test` (F6) verde.

**Testes:** os próprios testes.

#### T032 — Refinamentos F6 e smoke manual

**Objetivo:** fechar a F6: cobertura final, polimento pt-BR e smoke manual com chave real + credencial Cloudflare (TP §16 etapa 17).

**Contexto:** TP §16 etapa 17. Estado: **implementado (código) — smoke manual a executar com credenciais reais (Cloudflare)**.

**Implementação:**
- Rodar a suite completa (`php artisan test`) — F1–F6 verdes.
- Polimento pt-BR da página e mensagens; revisão de limites/erros (falha de IA → mensagem legível + nova tentativa).
- Smoke manual: vaga confirmed real + currículo → adequar (chave real do OpenCode Zen (`OPENCODE_ZEN_API_KEY`)) → conferir os 5 resultados → "Baixar PDF" (credencial Cloudflare Browser Run) → conferir PDF legível/ATS. Requer worker ativo (`php artisan dev` ou `queue:work`) para a análise em segundo plano.
- Registrar resultados em `storage/logs/ai.log`.

**Dependências:** T031.

**Critérios de conclusão:** suite verde; smoke real da adequação + PDF validado; F6 alinhada aos critérios do PRD §8.

**Testes:** suite completa; smoke manual (fora de CI).

#### T033 — Entrada do currículo em PDF/DOCX (extração de texto) — **bloqueada**

**Objetivo:** habilitar PDF/DOCX como formatos de entrada do currículo, convertendo o arquivo para texto antes da análise (a IA só recebe texto) (TP §10).

**Contexto:** TP §10 (formatos de entrada), §17 (pendência de extração de texto); PRD F3 (formato de entrada **PENDENTE**). Estado: **bloqueada — depende da decisão de extração de texto (TP §17)**: a biblioteca/abordagem não está definida e **não deve ser inventada** nesta etapa.

**Implementação:**
- Bloqueada até a decisão: quando definida, implementar a abordagem aprovada — converter o arquivo para texto e salvar via `ResumeService::save` (fluxo normal: o conteúdo extraído segue o mesmo caminho do texto colado).
- Nenhuma implementação provisória.

**Dependências:** decisão de extração de texto (TP §17 — pendência); T015 (`ResumeService`).

**Critérios de conclusão:** decisão registrada; entrada PDF/DOCX convertida para texto e salva; sem impacto no fluxo existente.

**Testes:** a definir junto com a decisão (fora do MVP de F6).

## 3. Definition of Done do MVP

Condições objetivas (derivadas do PRD §7–8) — todas devem ser verdadeiras para considerar o MVP concluído:

1. **Aplicação funcionando:** Laravel 13 roda localmente; painel Filament `/admin` acessível com login (1 usuário seed).
2. **Cadastro de vaga:** descrição obrigatória (20–100.000 chars) + link opcional; vaga sem link salva normalmente; `collected_at` = data do dia; status `pending`; descrição preservada integralmente e imutável após o cadastro (correção = excluir e recadastrar).
3. **Estruturação por IA:** ação "Analisar com IA" (síncrona) envia a descrição via Laravel AI SDK → provider `opencode_zen` → `mimo-v2.5-free`; resposta validada pela aplicação (contrato JSON + DTO); sugestão gravada em `suggested_data`; status `needs_review`. Nenhuma chamada HTTP direta ao provedor.
4. **Revisão humana:** página de revisão mostra descrição original (read-only) + role/company/itens editáveis com origem ai/user; correção manual persiste após recarregar; Confirmar persiste os dados confirmados (itens com `ItemSource` correto, `suggested_data` apagado, status `confirmed`); Descartar volta a `pending`.
5. **Persistência dos dados confirmados:** apenas a versão revisada pelo usuário é armazenada como base; a sugestão do modelo não é mantida após a revisão.
6. **Vaga não confirmada fora das análises:** apenas vagas `confirmed` compõem frequências/combinações/tendências.
7. **Currículo:** cadastro e atualização em texto por idioma (PT-BR canônico e EN derivado por tradução de IA); novo envio substitui a versão daquele idioma; a versão PT-BR reflete na comparação imediatamente.
8. **Análises agregadas:** frequências absolutas e relativas por categoria, rankings, combinações recorrentes — cenário de aceitação: 3 vagas (2× Python, 1× React) → Python 2, React 1, percentuais corretos.
9. **Cruzamento mercado × currículo:** tabela Item | Categoria | Nº de vagas | % vagas | Currículo (Presente/Ausente); cenário PRD: Python presente, React ausente com frequências corretas.
10. **Lacunas:** itens frequentes nas vagas e ausentes do currículo, priorizados por frequência.
11. **Análise temporal básica:** série mensal por item; comparação 3×3 meses com classificação de tendência (±20%); aviso "dados insuficientes" com < 2 meses.
12. **Visualização no Filament:** Listagem/Cadastro de vagas, página de revisão, página do currículo, Mercado × Currículo, Tendências e Dashboard com widgets (stats, top conceitos, lacunas, gráfico mensal).
13. **Testes das funcionalidades críticas:** suite Pest verde (Unit + Feature) com fakes do SDK (sem chamadas reais em CI), cobrindo fluxo da vaga, análise, revisão, currículo, análises, comparação, temporal e páginas Filament.
14. **Smoke real concluído:** fluxo ponta-a-ponta com chave real validado no navegador (T022).
15. **Adequação (F6):** ação "Adequar currículo" (dropdown com um item por idioma; vaga `confirmed` com currículo daquele idioma cadastrado) gera a versão adaptada com os 5 resultados (adaptado, alterações, contemplados, ausentes, alertas); persistida uma versão por (vaga, idioma) — PT-BR e EN, sem histórico (T023/T027); currículo original nunca alterado.
16. **Integridade (F6):** todo conceito do currículo adaptado existe no currículo original; conceito novo ⇒ rejeição com mensagem; `covered_keywords`/`missing_requirements` consistentes (T026).
17. **PDF (F6):** exportação em PDF via `spatie/laravel-pdf` com driver `cloudflare` (Cloudflare Browser Run), derivado sob demanda do Blade de impressão, legível por ATS; DOCX fora do MVP (T029).
18. **Testes F6:** suite F6 verde com fakes (Agent + `Pdf::fake()`), sem chamadas reais; smoke real da adequação + PDF validado (T032).

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
T022 Smoke real + Definition of Done (MVP existente)
   ↓
T023 Persistência ── T024 DTO ──┬── T025 Agent (dep. T024)
                               └── T026 Integridade (dep. T016, T018)
   ↓
T027 ResumeAdaptationService (dep. T015, T018)
   ↓
T028 Testes do domínio F6
   ↓
T029 PDF (spatie/laravel-pdf + driver cloudflare)
   ↓
T030 Página Filament (não-CRUD)
   ↓
T031 Testes de integração F6
   ↓
T032 Refinamentos F6
(T033 entrada PDF/DOCX — bloqueada pela decisão de extração de texto)
```

| ID | Tarefa | Fase | Dependências | Resultado |
| -- | ------ | ---- | ------------ | --------- |
| T001 | Bootstrap do projeto Laravel 13 | A — Bootstrap | — | Laravel 13 rodando, suite default verde |
| T002 | Configuração do ambiente (.env, SQLite, pt_BR) | A — Bootstrap | T001 | Chaves de IA/banco no .env e .env.example, sem hardcode |
| T003 | Instalação do Filament 5 e painel admin | A — Bootstrap | T001, T002 | Painel /admin com login funcional |
| T004 | Laravel AI SDK + provider OpenCode Zen + smoke | A — Bootstrap | T001, T002 | Chamada real ao mimo-v2.5-free via SDK funcionando |
| T005 | Migrations de domínio (job_postings, items, resumes) | B — Banco e domínio | T001, T002 | Schema com índices/constraints; migrate:fresh --seed ok |
| T006 | Models e Enums do domínio | B — Banco e domínio | T005 | Eloquent + enums com casts e relações corretos |
| T007 | Factories e Seeder de desenvolvimento | B — Banco e domínio | T005, T006 | Factories úteis + usuário admin seed |
| T008 | Resource Filament de vagas (List/Create) | C — Cadastro | T003, T005, T006 | Cadastrar/listar/excluir vagas (pending, collected_at=now) |
| T009 | Agent de extração (JobExtractionAgent) | D — IA | T004, T005, T006 | Agent provider opencode_zen, modelo, timeout 120s, contrato JSON |
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
| T023 | Persistência da adequação (migration + Model `ResumeAdaptation`) | L — F6 | T005, T006 | Tabela com UNIQUE (`job_posting_id`, `language`) — uma por (vaga, idioma), sem histórico; `resumes` intocada |
| T024 | DTO `ResumeAdaptationResult` | L — F6 | — (paralelo a T023) | Resposta da IA validada estruturalmente (5 campos, limites, sem HTML) |
| T025 | Agent `ResumeAdaptationAgent` | L — F6 | T004, T024 | Agent opencode_zen/mimo-v2.5-free com regra de integridade; sem Cloudflare/HTML |
| T026 | Validação de integridade (2 camadas) | L — F6 | T016, T018, T024 | Conceitos do adaptado ⊆ original; keywords/ausentes consistentes |
| T027 | Service `ResumeAdaptationService` | L — F6 | T023, T024, T025, T026, T015, T018, T014 | Fluxo completo com UPSERT por (vaga, idioma); falha não persiste nada |
| T028 | Testes do domínio F6 (DTO, integridade, UPSERT, original intocado) | L — F6 | T023, T024, T026, T027 | Cenários verdes com fakes, sem rede |
| T029 | PDF: `spatie/laravel-pdf` + driver `cloudflare` + `ResumePdfService` + Blade | L — F6 | T027, T002 | PDF sob demanda a partir do Blade; sem persistir PDF; sem DOCX |
| T030 | Filament `ResumeAdaptationPage` (não-CRUD) | L — F6 | T003, T008, T027, T029 | Página com os 5 resultados + Adequar novamente/Copiar/Baixar PDF |
| T031 | Testes de integração F6 (fluxo completo + PDF + Filament) | L — F6 | T028, T029, T030 | Fluxo ponta a ponta verde com `Pdf::fake()` e fakes do Agent |
| T032 | Refinamentos F6 e smoke manual | L — F6 | T031 | Suite verde + smoke real (adequação + PDF) validado |
| T033 | Entrada do currículo em PDF/DOCX (extração de texto) | L — F6 (bloqueada) | Decisão TP §17, T015 | Habilitada após decisão de extração; fora do MVP de F6 |

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
| F6 — Adequação de currículo para uma vaga (ATS) | T023 (persistência), T024 (DTO), T025 (agent), T026 (integridade), T027 (service), T028 (testes domínio), T029 (PDF), T030 (página Filament), T031 (testes integração), T032 (refinamentos) |
| Entrada do currículo PDF/DOCX (PENDENTE no PRD) | T033 (bloqueada pela decisão de extração de texto — TP §17) |

Revisão interna: toda funcionalidade do PRD (F1–F6), análise temporal, comparação agregada, lacunas e adequação de currículo possuem tarefa(s) correspondente(s); nenhuma funcionalidade fora do escopo (scraping, gestão de candidaturas, multi-usuário, carta de apresentação, candidatura automática, integração LinkedIn, envio automático, histórico temporal de versões do currículo/adaptação, DOCX no MVP, exportação CSV/JSON, histórico de currículo) foi adicionada.
