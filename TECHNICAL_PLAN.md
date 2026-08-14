# Plano Técnico de Implementação — Análise do Mercado de Trabalho a partir de Vagas de Emprego

> Documento de planejamento. Define a arquitetura alvo e a ordem de implementação.
> Nenhuma decisão aqui substitui o PRD; o PRD é a fonte do produto, este documento é a fonte da implementação.

## 1. Visão técnica

Aplicação Laravel 13 single-user com painel Filament. O usuário cadastra descrições de vagas (texto + link opcional de referência). As vagas são **fontes de dados**: um agente do Laravel AI SDK (provider OpenAI-compatible apontando para OpenCode Go, modelo `deepseek-v4-flash`) extrai dados estruturados da descrição; a sugestão passa por revisão humana; somente a versão confirmada é persistida como itens normalizados e passa a alimentar as análises agregadas — frequências por categoria, combinações recorrentes, evolução temporal e o posicionamento do currículo diante dessas tendências (aderências e lacunas).

O produto **não** é um gerenciador de vagas nem de candidaturas: a vaga é a unidade de observação que alimenta a base de mercado; a comparação com o currículo é uma visão derivada, não a finalidade principal.

Fluxo de dados: Filament (UI) → Services (análise, normalização, comparação) → Models (`JobPosting`, `JobPostingItem`, `Resume`) → SQLite. IA: `JobAnalysisService` → `JobExtractionAgent` (laravel/ai) → provider `opencode-go` → OpenCode Go. A aplicação nunca fala HTTP com o provedor diretamente.

## 2. Stack

- **Laravel 13** (requer PHP ≥ 8.3; ambiente local: PHP 8.5.8, Composer 2.10.0).
- **Filament 5** (`filament/filament ^5.0`) — painel admin.
- **Laravel AI SDK** (`laravel/ai ^0.x`) — camada de integração com modelos de linguagem.
- **OpenCode Go** — provedor (endpoint OpenAI-compatible).
- **Modelo**: `deepseek-v4-flash`.
- **Banco**: SQLite (`database/database.sqlite`, default do Laravel 13) — decisão do usuário.
- **Testes**: Pest (default do Laravel 13).
- Nenhuma outra tecnologia. Sem Redis, filas, Elasticsearch, microserviços (justificativa: escala pessoal, extração síncrona suficiente — ver §16 etapa 7 para a contingência, que usa a fila `database` nativa do Laravel, sem infra nova).

## 3. Arquitetura

Camadas: Models (Eloquent) / Enums / DTO / Services (lógica de negócio) / Agent (integração com IA) / Filament (Resource, Pages, Widgets).

- **Models**: `User` (default do Laravel), `JobPosting`, `JobPostingItem`, `Resume`.
- **Enums** (string-backed):
  - `JobPostingStatus`: `pending` | `needs_review` | `confirmed` | `failed` — ciclo de **processamento dos dados**, não status de candidatura.
  - `ConceptCategory`: `technology` | `language` | `framework` | `database` | `tool` | `cloud` | `methodology` | `skill` | `requirement` | `experience`.
  - `ItemSource`: `ai` | `user`.
- **DTO**: `JobAnalysisResult` — resultado estruturado e validado da extração (role, company, arrays por categoria). Sem DTOs adicionais no MVP.
- **Agent**: `JobExtractionAgent` (laravel/ai: implementa `Laravel\Ai\Contracts\Agent`, trait `Promptable`) — único ponto de contato com o SDK.
- **Services**:
  - `JobAnalysisService` — orquestra a extração: chama o agent, valida, normaliza, grava `suggested_data` e transiciona status.
  - `ConceptNormalizer` — normalização de nomes de conceitos (alias map, §9).
  - `ResumeService` — leitura/atualização do currículo (linha única).
  - `ResumeAnalyzer` — texto do currículo → conjunto de conceitos presentes.
  - `MarketAnalysisService` — frequências, rankings, combinações, série mensal.
  - `MarketComparisonService` — tabela mercado × currículo (frequência + presente/ausente + lacunas).
- **Actions**: nenhuma classe `Action` no MVP (as ações de confirmar/descartar/analisar são métodos das próprias páginas/actions do Filament; lógica de negócio vive nos Services). Criar Actions só se houver reuso real.
- **Jobs**: nenhum no MVP (fluxo síncrono). Contingência documentada na etapa 7: se o provedor ficar instável em produção, mover a extração para uma fila `database` do Laravel (sem Redis).
- **Filament**: 1 Resource (`JobPostingResource` — List/Create) + Pages custom (`JobReviewPage`, `ResumePage`, `MarketComparisonPage`, `MarketTrendsPage`) + Dashboard com widgets.
- Sem repository pattern (Eloquent usado direto nos services). Abstrações só onde há variação real: a IA é isolada atrás do Agent + SDK por ser substituível.

## 4. Modelo de domínio

### JobPosting (Vaga — unidade de observação)

- **Responsabilidade**: registrar a coleta de uma vaga e preservar a descrição original. É a unidade de observação que alimenta a base; não é o objeto central do produto.
- **Atributos**: `description` (text, obrigatória, **imutável após a criação** — original preservada), `source_url` (string nullable; **referência de origem apenas — não é identificador único e não gera deduplicação**), `collected_at` (datetime; automático = now na criação; base da análise temporal), `status` (enum `JobPostingStatus`, default `pending`), `role` (string nullable — cargo), `company` (string nullable — empresa), `suggested_data` (json nullable — sugestão da IA pendente de revisão; **apagada no confirm**), `error_message` (string nullable — último erro de análise), timestamps.
- **Relacionamentos**: `hasMany JobPostingItem` (cascade delete).
- **Origem**: description/url → usuário; collected_at/status → sistema; role/company → IA (sugerido) e usuário (confirmado).

### JobPostingItem (Conceito extraído)

- **Responsabilidade**: conceito extraído e normalizado — a **unidade de análise do mercado**. Toda métrica do produto deriva desta tabela.
- **Atributos**: `job_posting_id` (FK), `category` (enum `ConceptCategory`), `name` (string normalizada, ex.: "PostgreSQL", "Laravel", "AWS"), `source` (enum `ItemSource`: `ai` se aceito como veio da IA; `user` se editado ou adicionado pelo usuário), timestamps.
- **Relacionamento**: `belongsTo JobPosting`.
- **Origem**: IA (extração) + usuário (revisão). Derivados: nenhum — todas as métricas derivam desta tabela.
- **Representação das categorias**: coluna enum na mesma tabela, **não** tabelas separadas por categoria. Justificativa: todas as categorias sofrem as mesmas operações (frequência, ranking, combinação, presença no currículo); tabela única + índice `(category, name)` resolve as análises com `GROUP BY` simples e self-join para combinações; 10 tabelas separadas só repetiriam estrutura sem ganho. "Experiência" é categoria com texto normalizado ("3+ anos", "Sênior") — não campo numérico, para evitar taxonomia rígida.

### Resume (Currículo)

- **Responsabilidade**: currículo corrente — fonte da comparação com o mercado. **Não** faz parte de fluxo de candidatura.
- **Atributos**: `content` (text, obrigatório), timestamps.
- **Relacionamentos**: nenhum (linha única garantida pelo `ResumeService`).
- **Origem**: 100% usuário. Derivados: conjunto de conceitos presentes (computado pelo `ResumeAnalyzer`, não persistido).

## 5. Banco de dados (SQLite)

Migrations:

1. **users** — default do Laravel (id, name, email, password, timestamps). Seed: 1 usuário (via seeder ou `php artisan make:filament-user`).
2. **job_postings**
  - `id` BIGINT PK AUTOINCREMENT
  - `description` TEXT NOT NULL
  - `source_url` TEXT NULL (sem UNIQUE — link é só referência)
  - `collected_at` DATETIME NOT NULL
  - `status` VARCHAR(20) NOT NULL DEFAULT 'pending' — CHECK em ('pending','needs_review','confirmed','failed')
  - `role` VARCHAR(255) NULL
  - `company` VARCHAR(255) NULL
  - `suggested_data` TEXT NULL (JSON)
  - `error_message` TEXT NULL
  - `created_at`/`updated_at`
  - Índices: `collected_at` (análise temporal); `status` (filtros/widgets).
3. **job_posting_items**
  - `id` BIGINT PK
  - `job_posting_id` BIGINT NOT NULL FK → `job_postings.id` ON DELETE CASCADE
  - `category` VARCHAR(20) NOT NULL — CHECK nas 10 categorias
  - `name` VARCHAR(100) NOT NULL (sempre normalizado antes de salvar)
  - `source` VARCHAR(10) NOT NULL DEFAULT 'ai' — CHECK em ('ai','user')
  - `created_at`/`updated_at`
  - Índices: UNIQUE `(job_posting_id, category, name)` (sem duplicata por vaga); composto `(category, name)` (frequências).
4. **resumes**
  - `id` BIGINT PK
  - `content` TEXT NOT NULL
  - `created_at`/`updated_at`
  - `ResumeService` garante 1 linha: salvar = atualizar a linha existente.

**Eficiência das consultas agregadas**: frequências = `GROUP BY category, name` sobre o índice `(category, name)`; evolução temporal = JOIN com `job_postings.collected_at` (indexado) + `GROUP BY strftime('%Y-%m', collected_at)`; combinações = self-join sobre o índice `(job_posting_id)`. Nenhuma tabela de métricas materializada — tudo é calculado diretamente dos dados armazenados (volume pessoal, sem necessidade). `suggested_data` nunca entra nas agregações.

## 6. Fluxo de uma vaga

1. **Cadastro**: Create do `JobPostingResource` — textarea descrição (obrigatória, 20–100.000 chars) + input url (opcional). Salvar → `status=pending`, `collected_at=now`, descrição armazenada intacta. Descrição é imutável depois (correção = excluir e recadastrar).
2. **Análise**: ação "Analisar com IA" (na lista e na página de revisão), síncrona com indicador de loading. `JobAnalysisService`: `JobExtractionAgent->prompt(description)` → texto JSON → `JobAnalysisResult` (validação estrutural + normalização) → grava `suggested_data` + role/company sugeridos → `status=needs_review`.
3. **Revisão**: página de revisão — descrição original (read-only) + campos role/company + repeater de itens (category + name; badge de origem ai/user). Usuário edita, adiciona e remove itens.
4. **Confirmação**: ação "Confirmar" → persiste role/company + itens definitivos (`source='ai'` para itens inalterados, `'user'` para editados/adicionados), limpa `suggested_data`, `status=confirmed` → a vaga passa a compor as análises.
5. **Descartar**: ação "Descartar sugestão" → limpa `suggested_data`, `status=pending` (permite re-analisar).
6. **Falha**: exceção do SDK, JSON inválido ou validação → `status=failed` + `error_message` legível + log; ação "Tentar novamente" (repete a análise).

**Diferenciação IA × usuário**: `ItemSource` por item persiste no banco; a sugestão bruta (`suggested_data`) existe apenas durante a revisão e é removida no confirm (PRD: a sugestão do modelo não é mantida após a revisão).

## 7. Integração com IA

- **Provider** (`config/ai.php`), entrada nomeada `opencode-go`:
  ```php
  'opencode-go' => [
      'driver' => 'openai-compatible',
      'url' => env('AI_URL', 'https://opencode.ai/zen/go/v1'),
      'key' => env('AI_API_KEY'),
      'models' => [
          'text' => ['default' => env('AI_MODEL', 'deepseek-v4-flash')],
      ],
  ],
  ```
  O SDK anexa `/chat/completions` à base; endpoint efetivo: `https://opencode.ai/zen/go/v1/chat/completions`.
- **.env** (sem hardcode): `AI_URL`, `AI_API_KEY`, `AI_MODEL`. `.env.example` espelha as chaves.
- **Agent**: `JobExtractionAgent` (implementa `Laravel\Ai\Contracts\Agent`, trait `Promptable`): `provider()` → `opencode-go`; `model()` → lê `AI_MODEL` da config; `timeout()` → 120s; `instructions()` descreve a extração em pt-BR e exige resposta JSON crua (§8).
- **Structured output — restrição conhecida do provedor**: o OpenCode Go **rejeita** `response_format: json_schema` (HTTP 400, "This response_format type is unavailable now"). Portanto o agent **não** implementa `HasStructuredOutput`; o contrato JSON é embutido nas instruções e a resposta é validada na aplicação. Se o provedor passar a suportar json_schema, a migração é localizada: implementar `HasStructuredOutput` (o SDK valida) sem tocar no restante.
- **Chamada**: `$agent->prompt($posting->description)` → `AgentResponse` → `json_decode($response->text, true)`. Nenhum HTTP direto da aplicação; toda comunicação via SDK.
- **Validação em 2 camadas**: (a) decodificação JSON + regras estruturais no `JobAnalysisResult::fromStructured` (tipos, limites, duplicados pós-normalização); (b) `JobAnalysisService` valida regras de negócio (category ∈ enum; name não vazio e ≤ 100 chars; ≤ 100 itens por vaga; description dentro dos limites) antes de gravar.
- **Erros/timeout**: `try/catch` em `JobAnalysisService` — qualquer `Throwable` (SDK, HTTP, JSON inválido) → `status=failed` + `error_message` legível + `Log::channel('ai')` (job_posting_id, modelo, sucesso/falha, latência; nunca API key). Timeout de request 120s no agent.
- **Desacoplamento**: trocar modelo = mudar `AI_MODEL`; trocar provedor = nova entrada em `config/ai.php` + apontar o agent (ajustar prompt/schema se necessário). Lógica de negócio intocada.
- **Testes/mocks**: fakes do SDK (`JobExtractionAgent::fake(...)` com respostas fixas: válida, JSON inválido, fora do schema, exceção) — sem chamadas reais em CI; smoke manual com chave real na etapa 7.

## 8. Structured Output (schema)

Contrato JSON retornado pelo modelo (arrays opcionais; strings curtas; nomes canônicos; sem duplicatas):

```json
{
  "role": "string | null",
  "company": "string | null",
  "technologies": ["string"],
  "languages": ["string"],
  "frameworks": ["string"],
  "databases": ["string"],
  "tools": ["string"],
  "cloud": ["string"],
  "methodologies": ["string"],
  "skills": ["string"],
  "requirements": ["string"],
  "experience": ["string"]
}
```

- **Mapeamento**: `role`/`company` → colunas de `job_postings`; demais chaves → `JobPostingItem` (category = chave do JSON). Itens que não se encaixam em nenhuma categoria são omitidos pelo modelo (instrução no prompt); **não** existe categoria "other" (evita ruído nas análises).
- **Instruções embutidas no prompt**: nomes canônicos e estáveis ("PostgreSQL", não "Postgres"; "Laravel", não "Laravel Framework"), sem variações do mesmo conceito, apenas o que está explícito na descrição, "responda APENAS com JSON válido, sem markdown".
- **Regras de negócio pós-parsing**: categorias desconhecidas descartadas; duplicados (pós-normalização) colapsados; limites (≤ 100 itens, ≤ 100 chars/nome); role/company truncados em 255 chars.

## 9. Normalização

- **Onde**: camada de serviço (`ConceptNormalizer`), aplicada na escrita — na sugestão da IA (antes de exibir para revisão) e novamente no confirm (idempotente). Assim o usuário revisa nomes canônicos e a base só guarda nomes canônicos. O currículo usa o **mesmo** normalizador para casar conceitos (consistência obrigatória entre as duas pontas).
- **Como**: `config/concepts.php` — aliases → canônico + keywords para matching do currículo:
  ```php
  return [
      'PostgreSQL' => ['aliases' => ['postgresql', 'postgres', 'pgsql'], 'keywords' => ['postgres']],
      'Laravel'    => ['aliases' => ['laravel framework', 'laravel'],  'keywords' => ['laravel']],
      'AWS'        => ['aliases' => ['amazon web services', 'aws'],    'keywords' => ['aws', 'amazon web services']],
      // cresce conforme o uso; nomes canônicos em Title Case
  ];
  ```
  Regras: trim, lowercase para lookup, colapso de whitespace; alias match exato sobre o nome extraído; canônico desconhecido mantém o nome limpo.
- **Onde não**: nem em triggers no banco, nem nos relatórios (relatórios só consomem nomes já canônicos).
- **Evolução futura (fora do MVP)**: tabela `concepts` gerenciável; hoje, edição via config.

## 10. Currículo

- **Armazenamento**: tabela `resumes` com linha única. `ResumeService::save(content)`: salvar = atualizar a linha existente — novo envio substitui o texto atual (sem histórico no MVP, conforme PRD).
- **Formato MVP**: texto puro colado em textarea. Upload de PDF/DOCX: **PENDENTE** (PRD) — fora do MVP.
- **Representação para análise**: `ResumeAnalyzer` normaliza o texto (lowercase, trim, colapso de whitespace) e, para cada conceito do `config/concepts.php`, verifica se algum `keyword` ocorre como substring no texto normalizado → conjunto de conceitos presentes (não persistido; calculado por request).
- **Comparação**: `MarketComparisonService` cruza as frequências agregadas das vagas `confirmed` com esse conjunto → item × frequência × presente/ausente. Nenhum fluxo de candidatura.

## 11. Análise mercado × currículo

`MarketComparisonPage` (custom page): tabela com filtro de categoria; colunas **Item | Categoria | Nº de vagas | % das vagas | Currículo (Presente/Ausente)**. Linhas frequentes nas vagas e ausentes no currículo são destacadas como lacunas, ordenadas por frequência desc. Exemplo-alvo (do PRD; percentuais relativos ao total de vagas `confirmed`):


| Item       | Presença nas vagas | Currículo |
| ---------- | ------------------: | --------- |
| PHP        | 78%                | Presente  |
| Laravel    | 62%                | Presente  |
| Docker     | 64%                | Presente  |
| AWS        | 48%                | Ausente   |
| Kubernetes | 31%                | Ausente   |


- Frequência absoluta = `COUNT(DISTINCT job_posting_id)` por `(category, name)` entre vagas `confirmed`; relativa = absoluta / total de vagas `confirmed` (mesmo escopo de período/filtro).
- Presença no currículo = conceito no conjunto do `ResumeAnalyzer`.
- **Enquadramento**: a presença/ausência é um atributo de cada **item agregado** (posicionamento do currículo diante das tendências), não um casamento binário vaga-a-vaga. O objetivo é identificar lacunas e aderências em relação ao mercado, não dizer se o usuário atende cada vaga.
- Tudo dinâmico, sem cache (escala pessoal).

## 12. Motor de análise (`MarketAnalysisService`)

- **Frequência absoluta**: `SELECT category, name, COUNT(DISTINCT job_posting_id) … WHERE status='confirmed' GROUP BY category, name` (com filtros opcionais de período/categoria).
- **Frequência relativa**: absoluta / `COUNT(DISTINCT id)` das vagas `confirmed` no mesmo escopo (calculada em PHP sobre o agregado).
- **Rankings**: `ORDER BY count DESC LIMIT N` (top N global ou por categoria). Cargos/empresas mais comuns: `GROUP BY role`/`company` direto em `job_postings` (não são itens).
- **Combinações recorrentes**: pares na mesma categoria — self-join de `job_posting_items` a/b (`a.job_posting_id = b.job_posting_id AND a.category = b.category AND a.name < b.name`) `GROUP BY a.name, b.name ORDER BY count DESC LIMIT N` (MVP: top 20 pares por categoria; combinações cross-categoria fora do MVP).
- **Aderências/lacunas**: ranking × `ResumeAnalyzer` (§11).
- **Evolução temporal**: §13.
- **Materialização**: nenhuma no MVP — recálculo sob demanda (SQLite + índices, volume pessoal). Se um dia ficar lento, cache simples de resultado de widget; não planejar agora.

## 13. Análise temporal

- **Base**: `collected_at` (indexado). Frequência por mês:
  ```sql
  SELECT strftime('%Y-%m', jp.collected_at) AS month, i.name, COUNT(DISTINCT jp.id)
  FROM job_posting_items i
  JOIN job_postings jp ON jp.id = i.job_posting_id
  WHERE jp.status = 'confirmed' [AND month BETWEEN ? AND ?]
  GROUP BY month, i.name;
  ```
- **Evolução**: série mensal por item (top N da categoria selecionada).
- **Crescimento/queda**: comparação entre dois períodos (padrão: últimos 3 meses completos × 3 anteriores) — delta absoluto e relativo por item; classificação subiu/desceu/estável (threshold ±20% de delta relativo); item novo no período atual marcado como "nova".
- **Dados insuficientes**: se houver menos de 2 meses distintos com vagas `confirmed`, exibir aviso "dados insuficientes" (PRD).
- **UI**: `MarketTrendsPage` (seletor de categoria + período; tabela mensal + gráfico de linhas via `ChartWidget` do Filament).

## 14. Filament

- **Panel**: `admin` (`php artisan make:filament-panel admin`). Locale da UI: pt_BR (app locale + traduções do Filament se disponíveis; senão inglês no MVP). Código em inglês, UI em pt-BR.
- **Resource — `JobPostingResource`**: páginas **List** (tabela: id, role, company, collected_at, badge de status, nº de itens; filtros: status, período, categoria; ações: "Analisar com IA", "Revisar", Excluir) e **Create** (descrição + url opcional + Salvar). Sem Edit/View CRUD genérico — a revisão tem página própria e a descrição é imutável.
- **Pages custom**:
  - `JobReviewPage` (`/job-postings/{record}/review`) — descrição original read-only + form role/company + repeater de itens (select de categoria + text de nome; badge de origem) + ações Confirmar / Descartar sugestão.
  - `ResumePage` (`/resume`) — textarea + Salvar (`ResumeService`); mostra data da última atualização.
  - `MarketComparisonPage` (`/market-comparison`) — tabela do §11 com filtro de categoria.
  - `MarketTrendsPage` (`/market-trends`) — §13.
- **Widgets (Dashboard)**:
  - `StatsOverviewWidget` — total de vagas, confirmadas, aguardando revisão, falhas, itens distintos.
  - `TopConceptsWidget` — top 10 por categoria (seletor).
  - `ComparisonSummaryWidget` — top lacunas (frequentes nas vagas, ausentes no currículo).
  - `TrendsChartWidget` — gráfico mensal dos top itens (`ChartWidget`).
- **Fluxos do usuário**:
  - Cadastrar vaga: `JobPostingResource` → Create (colar descrição, url opcional, Salvar).
  - Processar vaga: ação "Analisar com IA" (síncrona, loading) na lista/revisão.
  - Revisar/confirmar: página de revisão → Confirmar ou Descartar.
  - Atualizar currículo: `ResumePage`.
  - Consultar tendências: `MarketTrendsPage` + Dashboard.
  - Consultar aderência: `MarketComparisonPage` + Dashboard.
- Análises **não** são CRUD: páginas de leitura específicas (comparação/tendências), dashboard com widgets, currículo como formulário singleton.

## 15. Testes (Pest)

- **Unit**: `ConceptNormalizerTest` (alias map, case/trim, multiword, canônico desconhecido); `ResumeAnalyzerTest` (presença por keyword/substring, multiword); validação do `JobAnalysisResult` (tipos errados lançam; ruído limpo; limites de itens/char).
- **Feature (RefreshDatabase)**:
  - `JobPostingFlowTest` — criar (status pending, collected_at = now, descrição preservada) → analisar com fake (needs_review + suggested_data) → confirmar (itens persistidos com `source` correto, suggested_data limpo, status confirmed, vaga entra nas análises) → descartar (volta a pending) → falha (failed + error_message + retry).
  - `AnalysisTest` — frequências absolutas/relativas, rankings, combinações, lacunas/aderências com fixtures.
  - `TemporalTest` — evolução mensal com `collected_at` variados; aviso de dados insuficientes; classificação de tendência entre períodos.
  - `ResumeTest` — salvar/substituir; reflexo na comparação.
  - `MarketComparisonTest` — cenário do PRD (vagas com Python/React; currículo com Python sem React → Python Presente, React Ausente, frequências corretas).
- **Structured output**: fixtures de resposta (válida / JSON malformado / fora do schema / tipos errados) → caminhos ok/erro.
- **Integração laravel/ai**: testes com `Ai::fakeAgents()` / `JobExtractionAgent::fake(...)` (resposta válida; JSON inválido; exceção genérica → failed). Sem chamadas reais em CI.
- **Filament**: testes Livewire/TestCase — páginas renderizam (List, Review, Resume, Comparison, Trends); ações mudam estado (analisar com fake, confirmar, descartar); widgets exibem dados corretos.

## 16. Ordem de implementação

Cada etapa termina com seus testes verdes antes de avançar. Dependências: etapa 5 antes de 6–11; 7 antes de 8; 9 antes de 11; 10 antes de 11–13; 12/13 dependem de 10; 14 por último.

1. **Criação do Laravel** — `composer create-project laravel/laravel` (repo root contém PRD.md/TECHNICAL_PLAN.md → criar em diretório temporário e mover os arquivos para a raiz, preservando os .md); `.env` (`APP_*`, `DB_CONNECTION=sqlite`, `touch database/database.sqlite`); `php artisan migrate`; seed de 1 usuário. *Objetivo*: app Laravel 13 rodando (`php artisan serve` + página inicial). *Testes*: suite default verde.
2. **Configuração do ambiente** — `.env` com `AI_URL`/`AI_API_KEY`/`AI_MODEL`; `.env.example` espelhado; gitignore ok. *Resultado*: variáveis de IA lidas sem hardcode.
3. **Instalação do Filament** — `composer require filament/filament:"^5.0"` + `php artisan filament:install --panels`; `make:filament-panel admin`; login com o usuário seed. *Resultado*: painel admin acessível.
4. **Instalação/configuração do Laravel AI SDK** — `composer require laravel/ai`; `config/ai.php` com provider `opencode-go`; smoke manual (prompt simples com chave real). *Resultado*: chamada ao modelo via SDK funcionando.
5. **Banco/modelo de domínio** — migrations (users, job_postings, job_posting_items, resumes) + Models + Enums + factories. *Resultado*: `php artisan migrate` ok; `migrate:fresh --seed` reproduzível.
6. **Cadastro de vagas** — `JobPostingResource` List/Create (validação: description obrigatória 20–100.000 chars; url opcional). *Resultado*: criar/listar/excluir vagas com status pending.
7. **Integração com IA** — `JobExtractionAgent` + contrato JSON + `JobAnalysisResult` + `JobAnalysisService` + validações + tratamento de erros/timeout + `Log::channel('ai')`; ação "Analisar com IA" síncrona. *Resultado*: pending → needs_review/failed com sugestão gravada. *Testes*: fakes do SDK. *Contingência* (somente se o provedor se mostrar instável em uso real): mover a extração para fila `database` do Laravel — decisão não antecipada.
8. **Revisão dos dados** — página de revisão + Confirmar/Descartar (persiste itens com `ItemSource`, limpa `suggested_data`). *Resultado*: vaga confirmed com itens; correção manual persiste após recarregar.
9. **Currículo** — migration `resumes` + `ResumeService` + `ResumePage`. *Resultado*: salvar/substituir currículo.
10. **Análises** — `MarketAnalysisService` (frequências, rankings, combinações) + testes. *Resultado*: com 3 vagas (2× Python, 1× React), relatório lista Python 2 e React 1.
11. **Comparação mercado × currículo** — `ResumeAnalyzer` + `MarketComparisonService` + `MarketComparisonPage`. *Resultado*: tabela item × freq × presente/ausente (cenário do PRD).
12. **Dashboard** — widgets (stats, top conceitos, lacunas, tendências). *Resultado*: visão agregada na home.
13. **Análise temporal** — `MarketTrendsPage` (série mensal, comparação de períodos, aviso de dados insuficientes). *Resultado*: evolução por mês.
14. **Testes e refinamentos** — cobertura final (fluxo completo + Filament), polimento pt-BR, revisão de limites/erros. *Resultado*: suite verde + smoke manual ponta-a-ponta.

## 17. Decisões pendentes

**Já decidido (definitivas)**: Laravel 13; Filament; Laravel AI SDK; OpenCode Go; endpoint `https://opencode.ai/zen/go/v1/chat/completions`; modelo `deepseek-v4-flash`; banco relacional SQLite; single-user; vagas como fonte de dados (não gerenciador de vagas/candidaturas); link opcional e não identificador (sem deduplicação por link); sem scraping; comparação agregada (não vaga-a-vaga binária); currículo atualizável (substituição); sem infra adicional (Redis, filas, Elasticsearch, microserviços).

**Recomendações técnicas (implementar sem perguntar)**: extração síncrona no MVP (contingência: fila `database` do Laravel se instável — sem Redis); normalização via `config/concepts.php`; `ItemSource` por item; UI pt-BR / código em inglês; sem materialização de métricas; limites de validação (description 20–100k chars, ≤ 100 itens/vaga, ≤ 100 chars/nome); aviso de dados insuficientes &lt; 2 meses; comparação de períodos 3×3 meses com threshold ±20%; structured output via contrato JSON nas instruções + validação no app (provedor rejeita `json_schema`).

**Pendências reais (decisão do usuário — nenhuma bloqueia a implementação)**:

- Nome do produto (PRD: **PENDENTE**) — afeta título/branding da UI.
- Formato do currículo além de texto simples, PDF/DOCX (PRD: **PENDENTE**) — MVP usa texto puro; decisão só necessária para versões futuras.

