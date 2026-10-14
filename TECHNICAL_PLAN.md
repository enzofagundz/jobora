# Plano Técnico de Implementação — Análise do Mercado de Trabalho a partir de Vagas de Emprego

> Documento de planejamento. Define a arquitetura alvo e a ordem de implementação.
> Nenhuma decisão aqui substitui o PRD; o PRD é a fonte do produto, este documento é a fonte da implementação.

## 1. Visão técnica

Aplicação Laravel 13 single-user com painel Filament. O usuário cadastra descrições de vagas (texto + link opcional de referência). As vagas são **fontes de dados**: um agente do Laravel AI SDK (provider opencode_zen, modelo `mimo-v2.5-free`) extrai dados estruturados da descrição; a sugestão passa por revisão humana; somente a versão confirmada é persistida como itens normalizados e passa a alimentar as análises agregadas — frequências por categoria, combinações recorrentes, evolução temporal e o posicionamento do currículo diante dessas tendências (aderências e lacunas).

O produto **não** é um gerenciador de vagas nem de candidaturas: a vaga é a unidade de observação que alimenta a base de mercado; a comparação com o currículo é uma visão derivada, não a finalidade principal. Como aplicação complementar da mesma análise, o produto gera uma versão do currículo adequada a uma vaga específica (F6 — Adequação de currículo para ATS): a IA adapta o texto do currículo com base na descrição e nos dados estruturados da vaga, e a versão adaptada pode ser exportada em PDF — renderizado via `spatie/laravel-pdf` com o driver Cloudflare (Cloudflare Browser Run API). A Cloudflare atua exclusivamente como driver de renderização de PDF; a IA da adequação usa o mesmo provedor OpenCode Zen (modelo `mimo-v2.5-free`).

Fluxo de dados: Filament (UI) → Services (análise, normalização, comparação, adequação) → Models (`JobPosting`, `JobPostingItem`, `Resume`, `ResumeAdaptation`) → SQLite. IA: `JobAnalysisService` → `JobExtractionAgent` (laravel/ai) → provider `opencode_zen` → OpenCode Zen; adequação (F6): `ResumeAdaptationService` → `ResumeAdaptationAgent` (laravel/ai) → mesmo provider `opencode_zen`. PDF (F6): `ResumePdfService` → `spatie/laravel-pdf` (driver `cloudflare`) → Cloudflare Browser Run. A aplicação nunca fala HTTP com o provedor de IA diretamente; a única integração com a Cloudflare é o driver de PDF.

## 2. Stack

- **Laravel 13** (requer PHP ≥ 8.3; ambiente local: PHP 8.5.8, Composer 2.10.0).
- **Filament 5** (`filament/filament ^5.0`) — painel admin.
- **Laravel AI SDK** (`laravel/ai ^0.x`) — camada de integração com modelos de linguagem.
- **OpenCode Zen** — provedor (via Laravel AI SDK).
- **Modelo**: `mimo-v2.5-free`.
- **Banco**: SQLite (`database/database.sqlite`, default do Laravel 13) — decisão do usuário.
- **Testes**: Pest (default do Laravel 13).
- **`spatie/laravel-pdf`** — geração do PDF do currículo adaptado (F6), com driver `cloudflare` (Cloudflare Browser Run API); sem Chromium/Puppeteer local.
- Nenhuma outra tecnologia. Sem Redis, filas, Elasticsearch, microserviços (justificativa: escala pessoal, extração síncrona suficiente — ver §16 etapa 7 para a contingência, que usa a fila `database` nativa do Laravel, sem infra nova).

## 3. Arquitetura

Camadas: Models (Eloquent) / Enums / DTO / Services (lógica de negócio) / Agent (integração com IA) / Filament (Resource, Pages, Widgets).

- **Models**: `User` (default do Laravel), `JobPosting`, `JobPostingItem`, `Resume`, `ResumeAdaptation`.
- **Enums** (string-backed):
  - `JobPostingStatus`: `pending` | `needs_review` | `confirmed` | `failed` — ciclo de **processamento dos dados**, não status de candidatura.
  - `ConceptCategory`: `technology` | `language` | `framework` | `database` | `tool` | `cloud` | `methodology` | `skill` | `requirement` | `experience`.
  - `ItemSource`: `ai` | `user`.
  - `ResumeLanguage`: `pt-BR` | `en` — idioma da versão de currículo e da adaptação.
- **DTO**: `JobAnalysisResult` — resultado estruturado e validado da extração (role, company, arrays por categoria); `ResumeAdaptationResult` — resultado validado da adequação (F6): currículo adaptado + alterações + contemplados + ausentes + alertas (§8). Sem DTOs adicionais no MVP.
- **Agent**: `JobExtractionAgent` (laravel/ai: implementa `Laravel\Ai\Contracts\Agent`, trait `Promptable`) — extração (F2). `ResumeAdaptationAgent` — adequação (F6), mesmo contrato, mesmo provider `opencode_zen` e modelo `mimo-v2.5-free`. Agents são o único ponto de contato com o SDK.
- **Services**:
  - `JobAnalysisService` — orquestra a extração: chama o agent, valida, normaliza, grava `suggested_data` e transiciona status.
  - `ConceptNormalizer` — normalização de nomes de conceitos (alias map, §9).
  - `ResumeService` — leitura/atualização do currículo por idioma (uma versão por (usuário, idioma); o PT-BR é o canônico).
  - `ResumeAnalyzer` — texto do currículo → conjunto de conceitos presentes.
  - `MarketAnalysisService` — frequências, rankings, combinações, série mensal.
  - `MarketComparisonService` — tabela mercado × currículo (frequência + presente/ausente + lacunas).
  - `ResumeAdaptationService` — orquestra a adequação (F6): reúne o currículo do idioma escolhido + vaga (descrição + itens estruturados) + posicionamento mercado × currículo, chama o agent, valida estrutura e integridade, persiste uma versão por (vaga, idioma).
  - `ResumePdfService` — currículo adaptado → HTML/Blade de impressão → PDF via `spatie/laravel-pdf` (driver `cloudflare`).
- **Actions**: nenhuma classe `Action` no MVP (as ações de confirmar/descartar/analisar são métodos das próprias páginas/actions do Filament; lógica de negócio vive nos Services). Criar Actions só se houver reuso real.
- **Jobs**: nenhum no MVP (fluxo síncrono). Contingência documentada na etapa 7: se o provedor ficar instável em produção, mover a extração para uma fila `database` do Laravel (sem Redis).
- **Filament**: 1 Resource (`JobPostingResource` — List/Create) + Pages custom (`JobReviewPage`, `ResumePage`, `MarketComparisonPage`, `MarketTrendsPage`, `ResumeAdaptationPage`) + Dashboard com widgets.
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

### Resume (Currículo por idioma)

- **Responsabilidade**: currículo em versões por idioma — a versão PT-BR é o **currículo canônico** e fonte da comparação com o mercado; a versão EN existe para candidatura. **Não** faz parte de fluxo de candidatura.
- **Atributos**: `content` (text, obrigatório), `language` (enum `ResumeLanguage`; UNIQUE com `user_id`), `source_fingerprint` (string nullable — fingerprint do PT-BR de origem da versão EN, para detecção de drift), timestamps.
- **Relacionamentos**: nenhum (uma linha por (usuário, idioma) garantida pelo `ResumeService`).
- **Origem**: PT-BR 100% usuário; EN derivada do PT-BR por **tradução de IA**, editável à mão e sinalizada como desatualizada (**drift**) quando o PT-BR muda depois da tradução. Derivados: conjunto de conceitos presentes (computado pelo `ResumeAnalyzer`, não persistido).

### ResumeAdaptation (Currículo adaptado por vaga e idioma)

- **Responsabilidade**: resultado da adequação (F6) para uma vaga e idioma — uma versão por (vaga, idioma), no máximo PT-BR e EN, sem histórico temporal. O currículo original (`Resume`) continua sendo a fonte: a adaptação nunca o substitui.
- **Atributos**: `job_posting_id` (FK; UNIQUE composto com `language` — uma adaptação por (vaga, idioma)), `language` (enum `ResumeLanguage`), `adapted_content` (text, obrigatório — texto do currículo adaptado), `changes` (json nullable — explicação das principais alterações), `covered_keywords` (json nullable — requisitos/palavras-chave contemplados), `missing_requirements` (json nullable — requisitos relevantes ausentes), `alerts` (json nullable — alertas de integridade), timestamps.
- **Relacionamentos**: `belongsTo JobPosting` (uma por (vaga, idioma); regenerar = atualizar a linha daquele idioma).
- **Origem**: derivado pela IA (F6) e validado pela aplicação; o usuário revisa antes de exportar. O PDF é uma representação/exportação derivada do `adapted_content` no idioma da adaptação (não é persistido; gerado sob demanda).
- **Justificativa**: a adaptação é específica da vaga e do idioma (não faz sentido global); regenerar substitui a versão daquele idioma (PRD: uma versão por (vaga, idioma)); histórico complexo de versões é desnecessário no MVP — o original sempre permanece em `resumes`.

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
  - `user_id` BIGINT FK → `users.id`
  - `content` TEXT NOT NULL
  - `language` TEXT NOT NULL (default `pt-BR`)
  - `source_fingerprint` TEXT NULL (fingerprint do PT-BR de origem da versão EN; base do aviso de drift)
  - `created_at`/`updated_at`
  - UNIQUE (`user_id`, `language`): `ResumeService` garante uma versão por (usuário, idioma); salvar = atualizar a linha daquele idioma.
5. **resume_adaptations**
  - `id` BIGINT PK
  - `job_posting_id` BIGINT NOT NULL FK → `job_postings.id` ON DELETE CASCADE
  - `language` TEXT NOT NULL (default `pt-BR`)
  - `adapted_content` TEXT NOT NULL
  - `changes` TEXT NULL (JSON)
  - `covered_keywords` TEXT NULL (JSON)
  - `missing_requirements` TEXT NULL (JSON)
  - `alerts` TEXT NULL (JSON)
  - `created_at`/`updated_at`
  - UNIQUE (`job_posting_id`, `language`) garante 1 adaptação por (vaga, idioma) (regenerar = UPSERT daquele idioma); sem tabela de histórico no MVP.

**Eficiência das consultas agregadas**: frequências = `GROUP BY category, name` sobre o índice `(category, name)`; evolução temporal = JOIN com `job_postings.collected_at` (indexado) + `GROUP BY strftime('%Y-%m', collected_at)`; combinações = self-join sobre o índice `(job_posting_id)`. Nenhuma tabela de métricas materializada — tudo é calculado diretamente dos dados armazenados (volume pessoal, sem necessidade). `suggested_data` nunca entra nas agregações.

## 6. Fluxo de uma vaga

1. **Cadastro**: Create do `JobPostingResource` — textarea descrição (obrigatória, 20–100.000 chars) + input url (opcional). Salvar → `status=pending`, `collected_at=now`, descrição armazenada intacta. Descrição é imutável depois (correção = excluir e recadastrar).
2. **Análise**: ação "Analisar com IA" (na lista e na página de revisão), síncrona com indicador de loading. `JobAnalysisService`: `JobExtractionAgent->prompt(description)` → texto JSON → `JobAnalysisResult` (validação estrutural + normalização) → grava `suggested_data` + role/company sugeridos → `status=needs_review`.
3. **Revisão**: página de revisão — descrição original (read-only) + campos role/company + repeater de itens (category + name; badge de origem ai/user). Usuário edita, adiciona e remove itens.
4. **Confirmação**: ação "Confirmar" → persiste role/company + itens definitivos (`source='ai'` para itens inalterados, `'user'` para editados/adicionados), limpa `suggested_data`, `status=confirmed` → a vaga passa a compor as análises.
5. **Descartar**: ação "Descartar sugestão" → limpa `suggested_data`, `status=pending` (permite re-analisar).
6. **Falha**: exceção do SDK, JSON inválido ou validação → `status=failed` + `error_message` legível + log; ação "Tentar novamente" (repete a análise).

**Diferenciação IA × usuário**: `ItemSource` por item persiste no banco; a sugestão bruta (`suggested_data`) existe apenas durante a revisão e é removida no confirm (PRD: a sugestão do modelo não é mantida após a revisão).

### Fluxo da adequação de currículo (F6)

1. **Disparo**: ação "Adequar currículo" na listagem como dropdown com um item por idioma (somente vaga `confirmed` com currículo daquele idioma cadastrado), síncrona com loading.
2. **Coleta**: `ResumeAdaptationService` reúne o currículo do idioma escolhido, descrição original + itens estruturados da vaga e o posicionamento mercado × currículo daquela vaga (presente/ausente via `ResumeAnalyzer`).
3. **Geração**: `ResumeAdaptationAgent->prompt(...)` → JSON → `ResumeAdaptationResult` (validação estrutural + integridade, §8).
4. **Persistência**: UPSERT em `resume_adaptations` por (`job_posting_id`, `language`) — regenerar substitui a versão daquele idioma, sem tocar na do outro nem em `resumes`.
5. **Apresentação**: página de resultado — versão adaptada, alterações, contemplados, ausentes, alertas.
6. **Exportação**: "Baixar PDF" → `ResumePdfService` (Blade de impressão no idioma da adaptação, com o rótulo "Currículo"/"Resume" → `spatie/laravel-pdf` driver `cloudflare` → Cloudflare Browser Run).
7. **Falha**: exceção do SDK/validação → erro legível na UI + `Log::channel('ai')`; nada é persistido; o status da vaga não é alterado.

## 7. Integração com IA

- **Provider** (`config/ai.php`), entrada nomeada `opencode_zen`:
  ```php
  'opencode_zen' => [
      'driver' => 'openai-compatible',
      'key' => env('OPENCODE_ZEN_API_KEY'),
      'url' => env('OPENCODE_ZEN_BASE_URL', 'https://opencode.ai/zen/v1'),
      'models' => [
          'text' => ['default' => env('OPENCODE_ZEN_MODEL', 'mimo-v2.5-free')],
      ],
  ],
  ```
  O SDK usa o driver `openai-compatible` com a base `https://opencode.ai/zen/v1` (configurada via `OPENCODE_ZEN_BASE_URL`; o SDK acrescenta `/chat/completions` automaticamente).
- **.env** (sem hardcode): `OPENCODE_ZEN_API_KEY`, `OPENCODE_ZEN_MODEL`. `.env.example` espelha as chaves.
- **Agent**: `JobExtractionAgent` (implementa `Laravel\Ai\Contracts\Agent`, trait `Promptable`): `provider()` → `opencode_zen`; `model()` → lê `OPENCODE_ZEN_MODEL` da config; `timeout()` → 120s; `instructions()` descreve a extração em pt-BR e exige resposta JSON crua (§8).
- **Structured output**: o agente **não** implementa `HasStructuredOutput`. O contrato JSON é embutido nas instruções do agente e a resposta é validada na aplicação por meio dos DTOs (por exemplo, `JobAnalysisResult::fromStructured`); não há uso de `response_format: json_schema` nativo.
- **Chamada**: `$agent->prompt($posting->description)` → `AgentResponse` → `json_decode($response->text, true)`. Nenhum HTTP direto da aplicação; toda comunicação via SDK.
- **Validação em 2 camadas**: (a) decodificação JSON + regras estruturais no `JobAnalysisResult::fromStructured` (tipos, limites, duplicados pós-normalização); (b) `JobAnalysisService` valida regras de negócio (category ∈ enum; name não vazio e ≤ 100 chars; ≤ 100 itens por vaga; description dentro dos limites) antes de gravar.
- **Erros/timeout**: `try/catch` em `JobAnalysisService` — qualquer `Throwable` (SDK, HTTP, JSON inválido) → `status=failed` + `error_message` legível + `Log::channel('ai')` (job_posting_id, modelo, sucesso/falha, latência; nunca API key). Timeout de request 120s no agent.
- **Desacoplamento**: trocar modelo = mudar `OPENCODE_ZEN_MODEL`; trocar provedor = nova entrada em `config/ai.php` + apontar o agent (ajustar prompt/schema se necessário). Lógica de negócio intocada.
- **Testes/mocks**: fakes do SDK (`JobExtractionAgent::fake(...)` com respostas fixas: válida, JSON inválido, fora do schema, exceção) — sem chamadas reais em CI; smoke manual com chave real na etapa 7.
- **Adequação (F6)**: `ResumeAdaptationAgent` — mesmo provider `opencode_zen`, mesmo modelo (`OPENCODE_ZEN_MODEL`, `mimo-v2.5-free`), timeout 120s, instruções pt-BR com o contrato JSON da F6 (§8) e a regra de integridade (reformular, nunca inventar). Também **não** implementa `HasStructuredOutput` (contrato JSON validado na aplicação, sem `response_format: json_schema` nativo). Erros: `try/catch` no `ResumeAdaptationService` → erro legível + `Log::channel('ai')`; sem persistência parcial. **A Cloudflare não é provedor de IA desta funcionalidade**: nenhuma chamada de modelo vai para Workers AI/AI Gateway — a Cloudflare participa apenas da renderização do PDF (§16 etapa 16).

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

### Structured output da adequação (F6)

Contrato JSON retornado pelo agente de adequação (conteúdo separado da apresentação — a IA **não** retorna HTML):

```json
{
  "adapted_resume": "string — currículo adaptado em texto (mesma natureza do currículo original), apenas com informações presentes no currículo do usuário",
  "changes": ["string — alteração realizada, ex.: \"Resumo reescrito com a terminologia da vaga\""],
  "covered_keywords": ["string — requisito/palavra-chave da vaga contemplado no currículo adaptado"],
  "missing_requirements": ["string — requisito relevante da vaga ausente do currículo"],
  "alerts": ["string — alerta de integridade, ex.: quando uma adaptação exigiria inventar informação"]
}
```

- **Separação conteúdo × apresentação**: `adapted_resume` é texto (o conteúdo); a apresentação visual é responsabilidade do Blade (template de impressão) e o PDF é gerado a partir desse HTML — a IA não produz HTML nem PDF.
- **Regra de integridade no contrato**: as instruções exigem que (a) todo fato no `adapted_resume` exista no currículo original — reformular/organizar/reordenar é permitido; inventar (experiências, tecnologias, cargos, certificações, resultados, projetos, responsabilidades, formação) é proibido; (b) requisitos da vaga sem correspondência no currículo vão para `missing_requirements`, nunca para o `adapted_resume`; (c) quando a vaga exigir algo que só poderia ser atendido inventando informação, registrar em `alerts`.
- **Validação de integridade pela aplicação** (2 camadas, como F2): (a) `ResumeAdaptationResult::fromStructured` — tipos e limites (≤ 50 itens por lista, ≤ 200 chars/item, `adapted_resume` ≤ 20.000 chars); (b) `ResumeAdaptationService` — **checagem de conceitos**: todo conceito canônico detectado no `adapted_resume` (via `ConceptNormalizer`/`ResumeAnalyzer`) deve existir no currículo original — conceito novo ⇒ resposta rejeitada com mensagem de integridade; `covered_keywords` devem corresponder a conceitos presentes no currículo; `missing_requirements` devem corresponder a itens da vaga ausentes do currículo (consistência com os dados estruturados).
- **Limite da validação automática**: conteúdo narrativo (redação, ênfase, ordem) não é verificável por regra — é coberto pelas instruções, pelos `alerts` e pela revisão humana antes da exportação.

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
- **Reuso na F6**: o mesmo normalizador alimenta a checagem de integridade da adequação (conceitos do adaptado ⊆ conceitos do currículo original) e o casamento com os itens da vaga.

## 10. Currículo

- **Armazenamento**: tabela `resumes` com uma versão por (usuário, idioma). `ResumeService::save(content, language)`: salvar = atualizar a linha daquele idioma — novo envio substitui o texto da versão, sem histórico no MVP (conforme PRD). A versão PT-BR (`ResumeLanguage::PtBr`) é o canônico; a EN é derivada dela por tradução de IA e rastreada por `source_fingerprint` (drift).
- **Formato MVP**: texto puro colado em textarea. Upload de PDF/DOCX: **PENDENTE** (PRD) — fora do MVP.
- **Formatos de entrada (PRD mantém PDF/DOCX)**: quando habilitados, o arquivo será **convertido para texto antes de qualquer análise** (a IA só recebe texto). Biblioteca/abordagem de extração de texto (PDF/DOCX): **PENDENTE** — não definida; registrar decisão antes de implementar (não antecipar dependência).
- **Não confundir**: formato de **entrada** do currículo (PDF/DOCX, PENDENTE) ≠ formato de **exportação** do currículo adaptado no MVP (PDF via `spatie/laravel-pdf` + driver `cloudflare`).
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
- **Reuso na F6**: o posicionamento por vaga (itens da vaga × presença no currículo) usa `ResumeAnalyzer` + `ConceptNormalizer` — os mesmos componentes da tabela agregada; a adequação consome esse posicionamento como entrada para o agente.

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
  - `ResumeAdaptationPage` (`/job-postings/{record}/adapt`) — §14 (F6), abaixo.
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
- **F6 — `ResumeAdaptationPage`** (`/job-postings/{record}/adapt`): página de resultado da adequação, **não** CRUD:
  - seleção da vaga: ação "Adequar currículo" na listagem (`JobPostingsTable`, linha de vaga `confirmed` com currículo cadastrado);
  - mostra a vaga (cargo/empresa), o currículo original (fonte) e o currículo adaptado;
  - painéis: alterações realizadas, palavras-chave/requisitos contemplados, requisitos ausentes (lacunas), alertas de integridade;
  - ações: "Copiar" (texto do adaptado), "Baixar PDF" (`ResumePdfService`), "Adequar novamente" (regenera e substitui);
  - avisos: sem currículo cadastrado ou vaga não confirmada ⇒ ação indisponível com mensagem;
  - **PDF**: template Blade próprio de impressão (`resources/views/pdf/adapted-resume.blade.php`) — A4, sem cores de fundo, texto selecionável, apropriado para impressão e leitura por ATS; gerado sob demanda (não persistido).

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
- **F6 — adequação**: Unit — `ResumeAdaptationResultTest` (tipos, limites, integridade: conceito inventado rejeitado); Feature — `ResumeAdaptationFlowTest` (geração com `ResumeAdaptationAgent::fake(...)`: válida / JSON inválido / fora do schema; UPSERT por (vaga, idioma); regenerar substitui a versão daquele idioma; currículo original intocado; sem adaptação para vaga não confirmada ou sem currículo daquele idioma); `ResumePdfTest` com `Pdf::fake()` (`spatie/laravel-pdf`) — PDF gerado do Blade com conteúdo correto; Filament — página de resultado renderiza e ações funcionam. Sem chamadas reais em CI.

## 16. Ordem de implementação

Cada etapa termina com seus testes verdes antes de avançar. Dependências: etapa 5 antes de 6–11; 7 antes de 8; 9 antes de 11; 10 antes de 11–13; 12/13 dependem de 10; etapas 15–17 (F6) dependem de 7–11 (9 e 11 em especial) e podem rodar em paralelo com 12–13; 14 e 17 por último.

1. **Criação do Laravel** — `composer create-project laravel/laravel` (repo root contém PRD.md/TECHNICAL_PLAN.md → criar em diretório temporário e mover os arquivos para a raiz, preservando os .md); `.env` (`APP_*`, `DB_CONNECTION=sqlite`, `touch database/database.sqlite`); `php artisan migrate`; seed de 1 usuário. *Objetivo*: app Laravel 13 rodando (`php artisan serve` + página inicial). *Testes*: suite default verde.
2. **Configuração do ambiente** — `.env` com `OPENCODE_ZEN_API_KEY`/`OPENCODE_ZEN_MODEL`; `.env.example` espelhado; gitignore ok. *Resultado*: variáveis de IA lidas sem hardcode.
3. **Instalação do Filament** — `composer require filament/filament:"^5.0"` + `php artisan filament:install --panels`; `make:filament-panel admin`; login com o usuário seed. *Resultado*: painel admin acessível.
4. **Instalação/configuração do Laravel AI SDK** — `composer require laravel/ai`; `config/ai.php` com provider `opencode_zen`; smoke manual (prompt simples com chave real). *Resultado*: chamada ao modelo via SDK funcionando.
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
15. **Adequação de currículo — IA (F6)** — migration `resume_adaptations` + Model `ResumeAdaptation` + `ResumeAdaptationAgent` + contrato JSON + `ResumeAdaptationResult` + `ResumeAdaptationService` (coleta, validação estrutural e de integridade, UPSERT) + testes com fakes. *Resultado*: adaptação gerada e persistida por (vaga, idioma), sem tocar no currículo original.
16. **Adequação — UI e PDF (F6)** — instalação/configuração do `spatie/laravel-pdf` + driver `cloudflare` + credenciais via env (sem hardcode) + `ResumePdfService` + template Blade de impressão + `ResumeAdaptationPage` + ação na listagem + testes com `Pdf::fake()`. *Resultado*: fluxo ponta a ponta com exportação PDF via Cloudflare Browser Run.
17. **Testes e refinamentos F6** — cobertura final da F6 (Unit + Feature + Filament + PDF) + smoke manual (chave real + credencial Cloudflare). *Resultado*: suite verde + smoke validado.

## 17. Decisões pendentes

**Já decidido (definitivas)**: Laravel 13; Filament; Laravel AI SDK; OpenCode Zen; provider `opencode_zen`; modelo `mimo-v2.5-free`; banco relacional SQLite; single-user; vagas como fonte de dados (não gerenciador de vagas/candidaturas); link opcional e não identificador (sem deduplicação por link); sem scraping; comparação agregada (não vaga-a-vaga binária); currículo atualizável por idioma (PT-BR canônico e EN derivado por tradução de IA, sem histórico); sem infra adicional (Redis, filas, Elasticsearch, microserviços). F6: adequação no MVP; IA da adequação = mesmo provedor OpenCode Zen + `mimo-v2.5-free` (Cloudflare **não** é provedor de IA); persistência de uma versão adaptada por (vaga, idioma) — PT-BR e EN — (sem histórico; original nunca substituído); exportação em PDF no MVP via `spatie/laravel-pdf` + driver `cloudflare` (Cloudflare Browser Run); DOCX fora do MVP; nome do produto definido no projeto (não é mais pendência).

**Recomendações técnicas (implementar sem perguntar)**: extração síncrona no MVP (contingência: fila `database` do Laravel se instável — sem Redis); normalização via `config/concepts.php`; `ItemSource` por item; UI pt-BR / código em inglês; sem materialização de métricas; limites de validação (description 20–100k chars, ≤ 100 itens/vaga, ≤ 100 chars/nome); aviso de dados insuficientes &lt; 2 meses; comparação de períodos 3×3 meses com threshold ±20%; structured output via contrato JSON nas instruções + validação no app (sem `response_format: json_schema` nativo). F6: adequação síncrona (mesmo padrão da extração); UPSERT em `resume_adaptations`; checagem de integridade por conceitos (mesmo normalizador); PDF testado com `Pdf::fake()`.

**Pendências reais (decisão do usuário — nenhuma bloqueia o MVP de F6)**:

- **Extração de texto de PDF/DOCX (entrada do currículo)**: biblioteca/abordagem não definida — decidir antes de habilitar esses formatos de entrada; não bloqueia o MVP (currículo em texto puro).
- **Credenciais Cloudflare para o driver `cloudflare` do `spatie/laravel-pdf`**: conta/token do Cloudflare Browser Run e nomes das variáveis de ambiente (sem hardcode) — necessárias apenas para a etapa 16 com chamada real; testes usam `Pdf::fake()`.

