# Jobora Job Capture (extensão)

Extensão de navegador (Chrome MV3 / Firefox MV2) deste monorepo que coleta LinkedIn publicações, LinkedIn Jobs e Indeed Jobs e envia envelopes V2 para `POST /api/page-captures`. Construída com [WXT](https://wxt.dev) + Vue 3 + TypeScript. `/api/job-captures` está aposentado sem efeito; não há fallback para captura V1. O usuário confirmou que userscripts e extensão antiga não estão mais em uso.

## Requisitos

- [Bun](https://bun.sh) ≥ 1.2
- Backend Jobora acessível em um origin permitido pela extensão e credencial individual de captura emitida no painel do proprietário.

## Instalação

```bash
bun install
```

## Comandos

| Comando | O que faz |
|---|---|
| `bun run dev` | Dev server Chromium (recarrega sozinho; adiciona `tabs`/`scripting` só em dev) |
| `bun run dev:firefox` | Dev server Firefox |
| `bun run build` | Build de produção Chromium → `.output/chrome-mv3/` |
| `bun run build:firefox` | Build de produção Firefox → `.output/firefox-mv2/` |
| `bun run zip` / `bun run zip:firefox` | Pacotes `.zip` para distribuição |
| `bun run compile` | Type-check (`vue-tsc --noEmit`) |
| `bun run test` | Testes unitários/de integração (Vitest) |

## Carregar a extensão no navegador

**Chrome/Edge/Brave:** `chrome://extensions` → ative "Developer mode" → "Load unpacked" → selecione `.output/chrome-mv3/`.

**Firefox:** `about:debugging#/runtime/this-firefox` → "Load Temporary Add-on" → escolha qualquer arquivo dentro de `.output/firefox-mv2/`.

## Configurar token e API

1. Clique no ícone da extensão.
2. **URL da API**: `https://vagas.test` (ou `https://jobora.test`; legados `http://localhost:8000` e `http://127.0.0.1:8000` continuam aceitos; sem barra final).
3. **Token**: obtenha a credencial individual no setup de captura do painel, cole no popup e clique em "Salvar". Não use token global compartilhado do `.env`.
4. Marque **Ativar captura** — o checkbox só habilita depois de salvar um token.
5. "Desconectar" remove o token e desmarca a captura; salvar um token novo nunca religa a captura sozinho.

A captura só roda com token salvo **e** "Ativar captura" marcado (persiste em `local:` storage). Abrir LinkedIn/Indeed sem esse estado não monta painel nem envia nada.

O popup mostra conexão, último resultado da API (enviada/duplicada/auth/invalid/http/rede/timeout) e orientação. A captura usa Bearer token individual associado ao proprietário; não exige login interativo no popup. Não compartilhe essa credencial.

## Testar (roteiro; não comprova homologação real)

O smoke real Hermes das três superfícies, com inspeção de JavaScript, rede e backend, ainda está incompleto (#38). Os passos abaixo são roteiro, não evidência de aprovação integral. #29 foi fechado por pedido do usuário sem essa comprovação.

Pré-requisitos: backend no ar, token válido configurado no popup **e** "Ativar captura" marcado; sessão ativa no LinkedIn (o Indeed não exige login).

- **LinkedIn Publicações**: abra `https://www.linkedin.com/search/results/content/?keywords=vaga%20php`. O painel aparece no canto inferior direito com contadores `Encontradas/Enviadas/Duplicadas/Erros`. Posts existentes e de infinite scroll são expandidos ("ver mais"), extraídos e enviados. Navegação SPA entra/sai da rota sem duplicar painel ou observers. Com captura ativa e sem interação prévia, a extensão rola sozinha até o fim absoluto (scrollIntoView no último post + overscroll por ciclo, para após 6 ciclos de 1200ms sem post/altura nova, teto 50); `Esc` ou botão Cancelar abortam.
- **LinkedIn Vagas**: abra `https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2`. Cada vaga da lista esquerda é clicada uma por vez (painel direito é estado compartilhado, como no Indeed), a descrição em `#job-details` é extraída e enviada com `title/company/location` preenchidos (`external_id=linkedin:job:<id>`, `url=/jobs/view/<id>`). Sem `currentJobId` em `search_url`. Também testado com `https://www.linkedin.com/jobs/search/?keywords=PHP`.
- **Indeed**: abra `https://br.indeed.com/jobs?q=php&l=Remoto`. Cada card é aberto um por vez (painel lateral é estado compartilhado), a descrição é extraída do painel e enviada. Sem descrição não há envio.
- Estados: token inválido → erro `auth` (401); API parada → `network`; payload rejeitado → `invalid`. Confirme que o proprietário correto recebe o `PageCapture` e que não ocorre escrita no endpoint V1.
- Fechar o painel (✕) oculta apenas a UI — a captura continua.
- Desmarcar "Ativar captura" (ou remover o token) remove o painel e para os envios na aba aberta; marcar de volta retoma sem reenviar o que já foi capturado na sessão.

## Adicionar um novo site

1. Novo entrypoint `entrypoints/<site>.content/index.ts` (`defineContentScript`, matches do host, `cssInjectionMode: 'ui'`, filtro de rota em runtime com `MatchPattern` + `wxt:locationchange`).
2. Módulo de extração em `sites/<site>/extract.ts` (seletores, expansão, payload) + controller (`sites/<site>/controller.ts`).
3. Contrato do envelope V2 em `types/capture.ts`, alinhado à API `/api/page-captures`; não reutilize o contrato V1 como equivalente.
4. Fixture + teste em `tests/fixtures/` e `sites/<site>/extract.test.ts`.
5. Host permission exata em `wxt.config.ts` (`manifest.host_permissions`) e atualização de `docs/extension-audit.md`.
6. O painel `components/CapturePanel.vue` é compartilhado — passe o rótulo do site por prop.

## Permissões (produção)

- `storage` (config/token)
- Hosts: `https://www.linkedin.com/*`, `https://*.indeed.com/*`, `https://vagas.test/*`, `https://jobora.test/*`, `http://localhost:8000/*`, `http://127.0.0.1:8000/*`

Sem `<all_urls>`, sem `tabs`/`activeTab`/`scripting` no manifest de produção (esses dois aparecem apenas no modo dev, injetados automaticamente pelo WXT para hot reload). Um deploy remoto da API exigirá adicionar o origin exato ao manifest antes de habilitá-lo no popup.

## Arquitetura

```
entrypoints/
  background.ts          service worker: resolve contexto de captura,
                         valida envelopes e gerencia fila durável autenticada
  linkedin.content/      dispatcher WXT/UI do LinkedIn — duas rotas SPA:
                         /search/results/content/* (publicações) e /jobs/search/* (vagas)
  indeed.content/        idem para o Indeed (/jobs)
  popup/                 popup Vue de configuração
sites/
  linkedin/              extract.ts (publicações) + controller.ts
  linkedin-jobs/         extract.ts (seletores vagas: data-job-id, #job-details) + controller.ts (fila serial)
  indeed/                extract.ts (painel lateral serial) + controller.ts
components/CapturePanel.vue   painel compartilhado (Shadow Root, px)
utils/                  toText (serializer), capture-session (WeakSet/Set/counters), storage
api/captures.ts         cliente POST /api/page-captures (timeout 10 s, classificação de status)
types/capture.ts        contrato do payload/resultados
docs/extension-audit.md auditoria funcional completa (contrato de migração)
tests/fixtures/         fixtures LinkedIn publicações, LinkedIn vagas (.refs/) e Indeed
```

Fluxo: o content script solicita `browser.runtime.sendMessage({ type: 'capture:resolve-run', url })` e recebe o contexto da captura. Depois de extrair o DOM, envia `browser.runtime.sendMessage({ type: 'capture:submit-envelope', envelope, context })`, usando o contexto retornado. O background valida a associação, persiste o envelope na fila durável e envia a `/api/page-captures` com a credencial individual. A confirmação durável do backend permite avançar; extração, Clef e triagem têm acompanhamento separado. Falhas recuperáveis permanecem na fila para retomada. O token nunca entra nas mensagens, no DOM ou nos logs.

Não existe main world, `unsafeWindow`, injeção de script nem dependência funcional do Violentmonkey — tudo roda no mundo isolado padrão dos content scripts.

## Limitações conhecidas

- A extensão aceita somente os origins de API declarados no manifest (localhost/127.0.0.1:8000 e https vagas.test/jobora.test).
- Seletores dependem do markup do LinkedIn/Indeed; fixtures não substituem homologação real. A auditoria existente documenta a migração histórica, não autoriza reativar userscripts.
- Deduplicação local é por sessão (memória); a ingestão V2 aplica seu próprio contrato server-side. Não presuma a antiga chave `unique(source, dedup_key)` de `JobCapture` como contrato de `PageCapture`.
