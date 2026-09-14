# Auditoria funcional — migração userscripts → extensão WXT

Contrato de migração gerado na Fase 1 antes de qualquer edição de `entrypoints/`, `api/`, `sites/`, `components/` ou `utils/`.
Fontes: `../vagas/userscripts/linkedin-job-capture.user.js` (0.1.0), `../vagas/userscripts/indeed-job-capture.user.js` (0.1.0), backend Laravel em `../vagas` (não alterado).

## 1. LinkedIn — `linkedin-job-capture.user.js`

### Metadata
- `@match https://www.linkedin.com/search/results/content/*`
- `@run-at document-idle`; `@connect localhost`; `@connect 127.0.0.1`

### Configuração
- `apiBaseUrl` padrão `http://localhost:8000`; path `/api/job-captures`; token via storage; `requestTimeoutMs: 10000`; `observerDebounceMs: 400`.

### Serializer `toText` (linhas 67–122)
- Sem root → `''`.
- Text nodes concatenados; `BR` → `\n`; `LI` → `- ` + filhos + `\n`; `A` → só `textContent` (texto visível).
- Tags de bloco (`P DIV SECTION ARTICLE HEADER FOOTER MAIN ASIDE UL OL TABLE BLOCKQUOTE PRE FIGURE H1..H6 HR`) cercam filhos com `\n`.
- Pós-processo: `[ \t]+`→`' '`, `\n{3,}`→`\n\n`, `trim()`.

### Detecção e expansão
- Card: `[role="listitem"]` com `a[href*="/feed/update/"]` **ou** `[data-testid="expandable-text-box"]` (`isPost`, linhas 431–434).
- Expansão (`expandPost`, 130–141): primeiro `button[aria-expanded="false"]` cujo `aria-label + textContent` casa `/mais|more/i`; clique único; espera 120 ms; máx. 1 tentativa.

### Extração (`extractLinkedInPost`, 149–184)
- Link `a[href*="/feed/update/"]`; `cleanHref` corta `?` e `#`.
- `externalId`: regex `urn:li:activity:(\d+)`; fallback `activity-(\d+)` → `urn:li:activity:<id>`.
- Descrição: `toText([data-testid="expandable-text-box"])`; fallback `toText(card inteiro)` quando o testid não existe.
- Autor: primeiro `a[href*="/in/"]`; `profileUrl = cleanHref(href)`; nome = `img.alt` ou texto do link; `{ name | null, profileUrl }`.
- `title`/`company`/`location`: sempre `null` (não inventar dados).

### Payload (`buildPayload`, 188–205)
- `source:'linkedin'`, `external_id`, `url`, `search_url = window.location.href` no momento do envio, `title/company/location: null`, `author` presente somente se `name || profileUrl`, `captured_at` ISO.

### Processamento (`processPost/processNewPosts`, 397–442)
- `WeakSet<Element>` por elemento (elemento processado não é re-tentado, mesmo após erro); `Set<string>` de external IDs enviados.
- Lote sequencial (`for...await`). Rejeição: sem `externalId` **e** sem descrição **e** sem URL → erro.
- Duplicidade local: `sentIds.has(externalId)` → contador duplicadas. Resultados: ok+duplicate→duplicadas, ok+!duplicate→enviadas, senão erros.

### Observer / SPA
- `MutationObserver(document.body, {childList:true, subtree:true})`, debounce 400 ms. O script NÃO trata history — a extensão deve acrescentar watcher oficial de SPA (`wxt:locationchange`) sem mudar a extração.
- Rota guard: `location.pathname.startsWith('/search/results/content')`.

### Painel
- Fixo canto inferior direito (`right/bottom:12px`, z-index 2147483647), título "Capturador de vagas", status "ativo", contadores `Encontradas`, `Enviadas`, `Duplicadas`, `Erros`, botão `✕` que apenas oculta (processamento continua).
- Debug `window.__vagasCapture = { counters, CONFIG }` (linha 494) vaza o token → **removido** na migração.

**Seletores a validar manualmente ao vivo** (fixture atual é simulação LinkedIn publicações; fixture de vagas usa `.refs/`): `[role="listitem"]`, `a[href*="/feed/update/"]`, `[data-testid="expandable-text-box"]`, `a[href*="/in/"]`, botões "ver mais". Para vagas: `li[data-occludable-job-id]`, `div[data-job-id]`, `a.job-card-container__link[href*="/jobs/view/"]`, `.job-card-container__metadata-wrapper`, `.scaffold-layout__detail.jobs-search__job-details`, `#job-details`, `button[aria-expanded="false"]` com `/mais|more/i`.

## 2. Indeed — `indeed-job-capture.user.js`

### Metadata
- `@match https://*.indeed.com/jobs*`; `@run-at document-idle`; mesmos `@connect`.

### Configuração
- Compartilhada + `paneWaitTimeoutMs: 12000`, `panePollMs: 150`.

### Detecção
- Card: `a[data-jk]` visível — `getClientRects().length > 0` (`isCard`, 479–481).
- Título do card: `span[title]` (`attr title || textContent`) ou fallback `h2` (427–429).

### Abertura/expansão
- Clique no card (`el.click()`) → espera painel `#jobsearch-ViewjobPaneWrapper` (`waitForPane`, 159–187): polling 150 ms até 12 s; sucesso quando existe descrição (`#jobDescriptionText` com texto) E (`vjk === jk` da URL OU título do cabeçalho === título do card).
- Expansão no painel (`expandPane`, 133–148): até 2 tentativas; mesmo match `/mais|more/i`; 150 ms entre tentativas.

### Extração (`extractJob`, 189–208)
- Título: `[data-testid="jobsearch-JobInfoHeader-title"]` (fallback título do card).
- Empresa: `[data-testid="inlineHeader-companyName"]` → fallback card `[data-testid="company-name"]`.
- Local: `[data-testid="inlineHeader-companyLocation"]` → fallback card `[data-testid="text-location"]`.
- Descrição: `toText(#jobDescriptionText)`. URL: `origin + '/viewjob?jk=' + jk`.

### Payload
- `source:'indeed'`, `external_id: jk`, `search_url` congelada na inicialização com `?&]vjk=[^&]*` removido, título/descrição/empresa/local, sem autor, `captured_at` ISO.

### Processamento (420–506)
- Fila estritamente serial `scanRunning/rescanQueued` (painel lateral é estado compartilhado). Sem `jk` → erro; painel não carregou → erro; descrição vazia → erro (nunca enviar parcial). `WeakSet` + `Set` idênticos ao LinkedIn.

### Observer/painel
- Idênticos ao LinkedIn; título visual "Indeed Job Capture". Rota guard: `pathname.startsWith('/jobs')`.

## 3. API e regras de negócio (backend não alterado)

- `POST /api/job-captures` (`routes/api.php:6-7`, middleware `auth.capture-token`), headers `Authorization: Bearer <token>` + `Content-Type: application/json`.
- Validação (`JobCaptureController.php:14-27`): `source` obrigatório max:50; `external_id/url/description` com `required_without_all` (pelo menos um); limites: external_id 255, url/search_url 2000, title/company/location 255, description 100000, author.name 255, author.profile_url 2000; `captured_at` date.
- Middleware (`EnsureCaptureApiToken.php`): config vazia → 503; Bearer ≠ esperado → 401 (`hash_equals`).
- Resposta: 201 `{capture, duplicate:false}` nova; 200 `duplicate:true` duplicada; 422 validação; 401 auth; 503 API sem token. Erros rede/timeout são estados locais.
- Dedup server-side (`JobCaptureService.dedupKey`): `ext:<external_id>` → `url:<sha1 scheme://host/path>` (sem query/fragment) → `content:<sha256 descrição normalizada>`. Unique `(source, dedup_key)`.
- CORS (`config/cors.php`): `paths api/*`, origens `*`, sem credenciais. Fetch no background funciona com host permission declarada; nenhuma alteração de backend necessária.
- Testes backend: `tests/Feature/JobCaptureFlowTest.php` (259 linhas) cobre 401/503/201/200-dedup ext/url/descrição/422/CORS preflight — permanece como referência de contrato.

## 4. Mapeamento completo de APIs Violentmonkey

| GM API | Uso nos scripts | Substituição v1 |
|---|---|---|
| `GM_getValue` | config apiBaseUrl/apiToken | `storage.defineItem('local:apiBaseUrl'|'local:apiToken', {fallback})` + `getValue()` async |
| `GM_setValue` | salvar config | `setValue()` nos mesmos itens |
| `GM_deleteValue` | **não usado** | "Limpar token" → `apiToken.removeValue()` |
| `GM_registerMenuCommand` | URL/token/limpar token | popup Vue com URL, token, estado, desconectar |
| `GM_xmlhttpRequest` | POST captura cross-origin | mensagem tipada content→background; `fetch` nativo no service worker (host_permissions declaradas) |
| fallback `localStorage` | storeGet/storeSet | **removido**; nunca usar localStorage dos sites |
| `unsafeWindow`, interceptação fetch/XHR, variáveis internas | **não encontrados** | — |

## 5. Distribuição de responsabilidades

- **Content scripts**: DOM, seletores, expansão, cliques, MutationObserver, navegação SPA, payload, painel.
- **Background/service worker**: ler token/configuração do storage, validar mensagem, fetch, timeout, classificação de status, persistir último estado da API.
- **Popup**: configuração e leitura do estado; sem endpoint de identidade no backend → "usuário conectado" exibido como "identidade não fornecida pela API", "token configurado" quando aplicável.
- **UI injetada**: painel Vue compartilhado (`components/CapturePanel.vue`), Shadow Root, estado específico por site.

## 6. Conclusão de mundo

Scripts usam apenas DOM, eventos, `window.location` e `MutationObserver`. Extensão usa mundo isolado padrão do WXT. Proibido nesta v1: `world: 'MAIN'`, `injectScript`, `unsafeWindow`, recursos web-accessible para lógica de captura.

## 7. Permissões (manifest produção)

- `permissions: ['storage']`.
- `host_permissions`: `https://www.linkedin.com/*`, `https://*.indeed.com/*`, `http://localhost:8000/*`, `http://127.0.0.1:8000/*`.
- Content scripts `matches`: `https://www.linkedin.com/*` e `https://*.indeed.com/*` com filtro de rota em runtime (SPA). Proibido: `<all_urls>`, `tabs`, `activeTab`, `scripting`, permissões opcionais. Nota: modo dev do WXT injeta `tabs`+`scripting` automaticamente (hot reload); aparecem só no manifest de desenvolvimento, não no de produção.
- Seletores das seções 1–2 marcados "a validar manualmente": a fixture existente é simulação LinkedIn e não há fixture Indeed real.

## Referências oficiais consultadas (Fase 0)

WXT instalado: `wxt ^0.21.3` (resolvido 0.21.4), `@wxt-dev/module-vue ^1.0.3`, Vue ^3.5.29.

| URL | Uso na implementação |
|---|---|
| wxt.dev/guide/installation.html | scripts oficiais dev/build/zip/compile/postinstall |
| wxt.dev/guide/essentials/project-structure.html | pastas entrypoints/components/utils/sites |
| wxt.dev/guide/essentials/entrypoints.html | `defineBackground(() => {})` (main não async), `defineContentScript({matches, runAt, main(ctx)})` (main pode async), convenção `entrypoints/<name>.content/index.ts`, popup/index.html → `action.default_popup` |
| wxt.dev/guide/essentials/extension-apis.html | browser/DOM só dentro de `main()` (build importa entrypoints em Node; fake-browser incompleto fora de main) |
| wxt.dev/guide/essentials/config/manifest.html | `manifest.permissions/host_permissions` em wxt.config.ts; name/version herdados de package.json; ícones auto de public/icon-*.png; dev adiciona tabs+scripting |
| wxt.dev/guide/essentials/content-scripts.html | `ContentScriptContext`: `ctx.addEventListener`, `ctx.setTimeout/setInterval`, `ctx.onInvalidated`, `ctx.isInvalid/signal`; `wxt:locationchange` + `MatchPattern`; `createShadowRootUi(ctx, {position:'overlay', anchor, zIndex, onMount, onRemove})`; `cssInjectionMode:'ui'`; rem não funciona em Shadow Root → px |
| wxt.dev/guide/essentials/messaging.html | `browser.runtime.sendMessage`/`onMessage` (único canal v1: content→background) |
| wxt.dev/storage.html | `storage.defineItem<T>(key, {fallback})`, prefixos `local:`/`session:`, getValue/setValue/removeValue/watch |
| github.com/wxt-dev/wxt/templates/vue | esqueleto local já idêntico — preservado |
| github.com/wxt-dev/examples/examples/vue-overlay | padrão `onMount: () => { const app = createApp(Component); app.mount(ui.container); return app; }` |

Assinaturas copiadas (não inventadas): listadas acima; verificação de build confirma cada uma.

## 8. Transição multiusuário — vínculo do token global (2026-08-26)

Durante esta transição o token Bearer existente (`VAGAS_API_TOKEN`) permanece single-account no backend: `EnsureCaptureApiToken` valida `Authorization: Bearer` via `hash_equals` e resolve o proprietário pelo `config('capture.owner_user_id')` que lê `env('VAGAS_API_USER_ID', 1)`. O cliente (WXT background + `utils/storage.ts`) continua enviando apenas o Bearer; não envia `user_id` no payload e não autenticará sessão web.

- `VAGAS_API_USER_ID` deve apontar para o `users.id` do owner inicial (na base auditada o menor `users.id` é o Admin semeado). Sem esse id ou com owner inexistente, `EnsureCaptureApiToken` responde `503` sem criar captura.
- `JobCaptureController::store` obtém o owner do atributo garantido pelo middleware (`capture_user`) e delega a `JobCaptureService::store($data, $owner)`. Deduplicação é por usuário (`UNIQUE(user_id, source, dedup_key)` / `external_id`); a resposta continua sem `user_id`/`deleted_at` (ocultos por `Hidden`).
- `ExtensionAuthController::validate` continua retornando `{valid: true}` apenas; ainda não fornece identidade ao cliente. O popup continua exibindo “identidade não fornecida pela API”.
- `CaptureSetupPage::canAccess()` restringe a exibição do token integral ao `owner_user_id`; demais usuários recebem `403` e não veem o token.

Pontos futuros para autenticação por usuário (fora desta tarefa):
`EnsureCaptureApiToken`, `ExtensionAuthController::validate`, `JobCaptureController`, `extension/utils/storage.ts` e `extension/entrypoints/background.ts`. Não foi criada autenticação paralela da extensão nem alterada a superfície `extension/api` ou content scripts nesta etapa.

## 10. LinkedIn Vagas — aba /jobs/search/* (2026-09-01)

Captura enriquecida da aba "Vagas" sem alterar backend (spec `linkedin-jobs-capture-spec.md`, discovery `linkedin-jobs-capture-discovery.md`, refs `.refs/closed-left-job-card.html` + `.refs/opened-right-job-panel.html`).

- **Rotas:** `https://www.linkedin.com/jobs/search/*` (MVP); `/jobs/view/<id>/` isolado e `/jobs/collections/*` fora. `entrypoints/linkedin.content/index.ts:1-83` virou dispatcher single-entrypoint com `isLinkedInRoute` (publicações) e `isLinkedInJobsRoute` (`sites/linkedin-jobs/extract.ts:4-18`, `MatchPattern https://www.linkedin.com/jobs/search/*`) + dois controllers (`createLinkedInController` e `createLinkedInJobsController`), sem nova host permission (`wxt.config.ts:10` já cobre `https://www.linkedin.com/*`).
- **Card (esquerda):** `li[data-occludable-job-id="4461568021"]` → `div[data-job-id="4461568021"]` (`closed-left-job-card.html:1,6`), título `a.job-card-container__link[href*="/jobs/view/"]` (`:31`), empresa `div.artdeco-entity-lockup__subtitle` (`:38`), local `ul.job-card-container__metadata-wrapper li span` (`:46`), link canônico `href="/jobs/view/<id>/?…"` limpo via `cleanHref`.
- **Painel (direita):** `div.scaffold-layout__detail.jobs-search__job-details` → `#job-details` (`opened-right-job-panel.html:1,326`, `div.jobs-description__content`), título `div.job-details-jobs-unified-top-card__job-title h1 a`, empresa `div.job-details-jobs-unified-top-card__company-name`, descrição `toText(#job-details)` (sem HTML), botão `button.inline-show-more-text__button[aria-expanded="false"]` com `/mais|more/i` (até 2 tentativas, linha 560).
- **Fluxo:** `sites/linkedin-jobs/controller.ts:1-120` — fila serial `scanRunning/rescanQueued` (painel único, como Indeed), `claim` **antes** do clique via `externalCaptureIdentity('linkedin','linkedin:job:<id>')` (`utils/capture-identity.ts:13`), `waitForJobsPane` (polling 150ms/12s, `currentJobId` OU `titleMatch` + `#job-details` com texto), `expandJobsPane`, `extractLinkedInJob`, `buildJobsPayload` (`source:'linkedin'`, `external_id:'linkedin:job:<id>'`, `url:'https://www.linkedin.com/jobs/view/<id>'`, `search_url` congelada removendo `currentJobId/position/pageNum` via `URLSearchParams`, `author:null`), `browser.runtime.sendMessage` → background.
- **Dedup:** `JobCaptureService.php:26` `ext:linkedin:job:<id>` (sem migration), `source='linkedin'` inalterado, `JobCaptureController.php:14` já aceita `title/company/location`.
- **Testes:** `sites/linkedin-jobs/extract.test.ts:1-130` (fixture `tests/fixtures/linkedin-jobs.html` derivada de `.refs/`) cobre `isLinkedInJobsRoute`, `isJobCard`, `jobIdFromCard` prioridade `data-job-id` > `occludable` > `href`, `frozenSearchUrl`, `extractLinkedInJob`, `buildJobsPayload`; `tests/linkedin-jobs-controller.test.ts:1-140` (5 cards, recriação, novo item, geração) espelha `tests/linkedin-controller.test.ts` e `tests/indeed-controller.test.ts`. `bun run build` gera `content-scripts/linkedin.js` com ambos os controllers.

## 9. Autenticação por usuário — credencial individual (2026-08-27)

Migração concluída para identidade por usuário:

- `users.capture_token_hash` (SHA-256, `UNIQUE`) + `capture_token_last_four` + `disabled_at` substituem `VAGAS_API_TOKEN`/`VAGAS_API_USER_ID`. O segredo em texto puro nunca é persistido; apenas o hash é armazenado e o `last_four` serve para máscara.
- `CaptureTokenService::issueToken()` gera `bin2hex(random_bytes(32))`, grava hash+last4 via `forceFill` e retorna o segredo uma única vez para exibição transitória. `revokeToken()` limpa ambos. `findUserByToken()` localiza o usuário por hash e rejeita `disabled_at` não nulo.
- `CaptureSetupPage` agora permite qualquer usuário autenticado (`canAccess()` checa `auth()->check()` e `disabled_at`). Ações de cabeçalho `generateToken`/`regenerateToken`/`revokeToken` (`Filament\Actions\Action`) operam exclusivamente sobre `auth()->user()`, com confirmação e `Notification` sobre invalidação. O Blade mostra `plainToken` apenas quando presente, caso contrário máscara (`••••`+last4) ou empty state "Você ainda não possui credencial".
- `EnsureCaptureApiToken` valida o Bearer por hash (`findUserByToken`), retorna 401 genérico para ausente/inválido/revogado/desativado/excluído, define o usuário na request (`setUserResolver`) e não consulta mais `config('capture.*')`. `JobCaptureController::store` consome `$request->user()` e mantém `user_id` fora do payload e da resposta (`Hidden`). `ExtensionAuthController::validate` confirma apenas a validade da credencial.
- `routes/api.php` protege `POST /api/job-captures` (`throttle:60,1`) e `GET /api/extension/auth/validate` (`throttle:30,1`) antes da autenticação, por IP, sem logar o token.
- Extensão WXT: `api/captures.ts` adiciona `VALIDATE_PATH` e `validateCaptureToken()` (GET com Bearer, timeout, classificação `200/401/http/timeout/network` sem logar token). `entrypoints/popup/App.vue` importa `validateCaptureToken`, muda o label para "Token (credencial da conta)" e, após salvar URL+token, valida e exibe "Conectado — credencial válida." ou erros específicos. `Desconectar` limpa storage e desliga captura localmente.
- Migração `2026_08_26_160000` cria `capture_token_hash`/`capture_token_last_four`/`disabled_at`; `2026_08_26_160001` converte o `VAGAS_API_TOKEN` legado no hash do owner se `VAGAS_API_USER_ID` ainda estiver configurado, falhando explicitamente se o owner não existir ou já possuir hash divergente. `config/capture.php` mantém as chaves legadas apenas para esse backfill; `.env.example` documenta a geração via `/capture-setup`.
- Compatibilidade: `User::canAccessPanel()` e `CaptureTokenService` bloqueiam `disabled_at`; `cascadeOnDelete` remove capturas ao excluir o usuário; logout web não revoga o token da extensão; um único token por usuário invalida todos os dispositivos ao regenerar (tokens por instalação ficam para evolução futura).

## 11. Jump automático ao fim — LinkedIn Publicações (2026-09-14)

Auto-scroll até o fim absoluto em `search/results/content/*` sem nova permissão/rota/API (spec `linkedin-jump-fim-absoluto-spec.md`).

- **Módulo:** `sites/linkedin/jump.ts` — `countPosts`/`getLastPost` via `isPost`, `runJumpToEnd` com `scrollIntoView(block:end)` no último post + overscroll 200 por ciclo e ajuste final, parada após `JUMP_STABLE_CYCLES=6` de `JUMP_SETTLE_MS=1200` sem count/altura nova, teto `JUMP_MAX_CYCLES=50`.
- **Controller:** `sites/linkedin/controller.ts` — veto pré-disparo (`wheel/keydown/mousedown/touchmove`), `startJump` pós-scan inicial com `AbortController`, `Esc` (`keydown` capture) ou botão Cancelar abortam, `deactivate`/`removeUi` limpam listeners via `isGenerationActive`.
- **Painel:** `components/CapturePanel.vue` — status `pulando para o fim · X posts` + Cancelar, `jump` reativo compartilhado.
