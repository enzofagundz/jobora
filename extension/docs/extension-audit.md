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

**Seletores a validar manualmente ao vivo** (fixture atual é simulação LinkedIn; não há fixture Indeed): `[role="listitem"]`, `a[href*="/feed/update/"]`, `[data-testid="expandable-text-box"]`, `a[href*="/in/"]`, botões "ver mais".

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
