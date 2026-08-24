// ==UserScript==
// @name         Jobora Job Capture — Indeed → API
// @namespace    vagas.local
// @version      0.1.0
// @description  Captura vagas da página de resultados do Indeed e envia à API do Jobora para revisão manual no painel.
// @match        https://*.indeed.com/jobs*
// @grant        GM_getValue
// @grant        GM_setValue
// @grant        GM_xmlhttpRequest
// @grant        GM_registerMenuCommand
// @connect      localhost
// @connect      127.0.0.1
// @run-at       document-idle
// ==/UserScript==

/*
 * INSTALAÇÃO
 * 1. Violentmonkey → "Novo script" → apagar o conteúdo → colar este arquivo inteiro → salvar (Ctrl+S).
 * 2. Abrir qualquer página de busca de vagas do Indeed
 * 3. No menu do script (ícone do Violentmonkey), definir:
 *    - "Definir URL da API": http://localhost:8000 (padrão; o painel Jobora rodando com `php artisan serve`)
 *    - "Definir token da API": o valor de VAGAS_API_TOKEN do .env do Jobora
 *
 * AVISO: se a API estiver hospedada em outro host, adicione `// @connect <host>` no metadata
 * acima — o Violentmonkey bloqueia requisições cross-origin sem o @connect correspondente.
 *
 * O script NÃO faz scraping automático: apenas observa a página aberta, abre o painel lateral
 * de cada vaga (a descrição completa só existe lá), extrai e envia para revisão manual no painel.
 * O processamento é estritamente sequencial — o painel lateral é estado compartilhado.
 */

(() => {
    'use strict';

    // ------------------------------------------------------------------ CONFIG

    const CONFIG = {
        apiBaseUrl: storeGet('apiBaseUrl') || 'http://localhost:8000',
        capturePath: '/api/job-captures',
        apiToken: storeGet('apiToken') || '',
        requestTimeoutMs: 10000,
        observerDebounceMs: 400,
        paneWaitTimeoutMs: 12000,
        panePollMs: 150,
    };

    // ------------------------------------------- STORAGE (GM com fallback)

    function storeGet(key) {
        if (typeof GM_getValue === 'function') return GM_getValue(key, '');
        try {
            return localStorage.getItem('vagasCapture:' + key) || '';
        } catch {
            return '';
        }
    }

    function storeSet(key, value) {
        if (typeof GM_setValue === 'function') return GM_setValue(key, value);
        try {
            localStorage.setItem('vagasCapture:' + key, value);
        } catch {
            /* sem storage disponível — configuração não persiste */
        }
    }

    // ------------------------------------------------------------ SERIALIZER

    const BLOCK_TAGS = new Set([
        'P', 'DIV', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'MAIN', 'ASIDE',
        'UL', 'OL', 'TABLE', 'BLOCKQUOTE', 'PRE', 'FIGURE',
        'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR',
    ]);

    /**
     * Serializa a estrutura de um elemento para texto, preservando quebras,
     * parágrafos, listas, links (texto visível), hashtags e e-mails.
     */
    function toText(root) {
        if (!root) return '';
        const parts = [];

        function walk(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                parts.push(node.nodeValue);
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return;

            const tag = node.tagName;
            if (tag === 'BR') {
                parts.push('\n');
                return;
            }
            if (tag === 'LI') {
                parts.push('- ');
                walkChildren(node);
                parts.push('\n');
                return;
            }
            if (tag === 'A') {
                parts.push(node.textContent);
                return;
            }
            if (BLOCK_TAGS.has(tag)) {
                parts.push('\n');
                walkChildren(node);
                parts.push('\n');
                return;
            }
            walkChildren(node);
        }

        function walkChildren(node) {
            for (const child of node.childNodes) walk(child);
        }

        walk(root);

        let out = parts.join('');
        out = out.replace(/[ \t]+/g, ' '); // colapsa espaços horizontais dentro da linha
        out = out.replace(/\n{3,}/g, '\n\n'); // 3+ quebras viram 2
        return out.trim();
    }

    // -------------------------------------------------------------- EXPAND

    /**
     * Expande a descrição truncada ("...ver mais") dentro do painel lateral.
     * Única interação DOM permitida; no máximo 2 tentativas, 150ms de intervalo.
     */
    async function expandPane(paneEl) {
        for (let attempt = 0; attempt < 2; attempt++) {
            const buttons = paneEl.querySelectorAll('button[aria-expanded="false"]');
            let clicked = false;
            for (const btn of buttons) {
                const label = ((btn.getAttribute('aria-label') || '') + ' ' + (btn.textContent || '')).trim();
                if (/mais|more/i.test(label)) {
                    btn.click();
                    clicked = true;
                    break;
                }
            }
            if (!clicked) return;
            await new Promise((r) => setTimeout(r, 150));
        }
    }

    // ------------------------------------------------------- EXTRACTION (fonte-específica: Indeed)

    const PANE = '#jobsearch-ViewjobPaneWrapper';

    /**
     * Espera o painel lateral carregar a vaga clicada. O parâmetro `vjk` da URL
     * é a chave primária de associação; o fallback compara o título do cabeçalho
     * com o título do card (mercados onde `vjk` não é atualizado).
     */
    function waitForPane(jk, cardTitle) {
        return new Promise((resolve) => {
            const started = Date.now();
            const timer = setInterval(() => {
                const pane = document.querySelector(PANE);
                const vjk = new URLSearchParams(location.search).get('vjk');

                const vjkMatch = vjk === jk;
                const title = pane
                    ? pane.querySelector('[data-testid="jobsearch-JobInfoHeader-title"]')
                    : null;
                const titleText = title ? title.textContent.trim() : '';
                const titleMatch = cardTitle && titleText.length > 0 && titleText === cardTitle;

                const desc = document.querySelector('#jobDescriptionText');
                const hasDescription = desc && desc.textContent.trim().length > 0;

                if (hasDescription && (vjkMatch || titleMatch)) {
                    clearInterval(timer);
                    resolve(true);
                    return;
                }
                if (Date.now() - started > CONFIG.paneWaitTimeoutMs) {
                    clearInterval(timer);
                    resolve(false);
                }
            }, CONFIG.panePollMs);
        });
    }

    async function extractJob(jk, cardTitle, cardEl) {
        const pane = document.querySelector(PANE);
        if (!pane) return null;

        await expandPane(pane);

        const q = (sel) => {
            const el = pane.querySelector(sel);
            return el ? el.textContent.trim() : null;
        };

        const title = q('[data-testid="jobsearch-JobInfoHeader-title"]') || cardTitle;
        const company = q('[data-testid="inlineHeader-companyName"]')
            || (cardEl.querySelector('[data-testid="company-name"]')?.textContent.trim() || null);
        const jobLocation = q('[data-testid="inlineHeader-companyLocation"]')
            || (cardEl.querySelector('[data-testid="text-location"]')?.textContent.trim() || null);
        const description = toText(document.querySelector('#jobDescriptionText'));

        return { jk, url: window.location.origin + '/viewjob?jk=' + jk, title, description, company, location: jobLocation };
    }

    // -------------------------------------------------------------- PAYLOAD

    // URL da busca congelada na inicialização — o clique na vaga muta a URL
    // adicionando `?vjk=<jk>`, então `location.href` no momento do envio estaria errada.
    const SEARCH_URL = location.href.replace(/[?&]vjk=[^&]*/, '');

    function buildPayload(raw) {
        return {
            source: 'indeed',
            external_id: raw.jk,
            url: raw.url,
            search_url: SEARCH_URL,
            title: raw.title,
            description: raw.description,
            company: raw.company,
            location: raw.location,
            captured_at: new Date().toISOString(),
        };
    }

    // -------------------------------------------------------------- SEND

    function statusToResult(status) {
        if (status === 200) return { ok: true, duplicate: true };
        if (status === 201) return { ok: true, duplicate: false };
        if (status === 401) return { ok: false, error: 'auth' };
        if (status === 422) return { ok: false, error: 'invalid' };
        return { ok: false, error: 'http' };
    }

    /**
     * Envia uma captura. Resolve { ok, duplicate?, error? } — falha de uma
     * captura nunca interrompe as demais.
     */
    function sendCapture(payload) {
        const url = CONFIG.apiBaseUrl.replace(/\/$/, '') + CONFIG.capturePath;

        console.group('[JOBORA] Enviando captura');
        console.log('URL:', url);
        console.log('Token configurado:', !!CONFIG.apiToken);
        console.log('Payload:', payload);
        console.log('JSON:', JSON.stringify(payload));
        return new Promise((resolve) => {
            if (typeof GM_xmlhttpRequest === 'function') {
                GM_xmlhttpRequest({
                    method: 'POST',
                    url,
                    headers: {
                        Authorization: 'Bearer ' + CONFIG.apiToken,
                        'Content-Type': 'application/json',
                    },
                    data: JSON.stringify(payload),
                    timeout: CONFIG.requestTimeoutMs,

                    onload: (res) => {
                        console.group('[JOBORA] Resposta da API');
                        console.log('Status:', res.status);
                        console.log('Status text:', res.statusText);
                        console.log('Headers:', res.responseHeaders);
                        console.log('Body:', res.responseText);
                        console.groupEnd();

                        const result = statusToResult(res.status);

                        console.log('[JOBORA] Resultado:', result);
                        console.groupEnd();

                        resolve(result);
                    },

                    onerror: (err) => {
                        console.error('[JOBORA] ERRO DE REDE:', err);
                        console.groupEnd();

                        resolve({
                            ok: false,
                            error: 'network',
                        });
                    },

                    ontimeout: () => {
                        console.error('[JOBORA] TIMEOUT');
                        console.groupEnd();

                        resolve({
                            ok: false,
                            error: 'timeout',
                        });
                    },
                });

                return;
            }

            fetch(url, {
                method: 'POST',
                headers: {
                    Authorization: 'Bearer ' + CONFIG.apiToken,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
                signal: AbortSignal.timeout
                    ? AbortSignal.timeout(CONFIG.requestTimeoutMs)
                    : undefined,
            })
                .then(async (res) => {
                    const body = await res.text();

                    console.group('[JOBORA] Resposta da API');
                    console.log('Status:', res.status);
                    console.log('Headers:', [...res.headers.entries()]);
                    console.log('Body:', body);
                    console.groupEnd();

                    resolve(statusToResult(res.status));
                })
                .catch((err) => {
                    console.error('[JOBORA] ERRO:', err);
                    console.groupEnd();

                    resolve({
                        ok: false,
                        error: err?.name === 'TimeoutError' ? 'timeout' : 'network',
                    });
                });
        });
    }

    // ------------------------------------------------------ SESSION MEMORY
    // Sem persistência de IDs (GM_setValue): o backend é a fonte da verdade e
    // responde `duplicate` — persistir aqui seria duplicar estado.

    const processedElements = new WeakSet(); // elemento já processado nesta página
    const sentIds = new Set(); // external_id já enviado nesta página

    // -------------------------------------------------------------- PANEL

    const counters = { found: 0, sent: 0, duplicates: 0, errors: 0 };
    let panel = null;
    let panelVisible = true;

    function counterLabels() {
        return [
            ['Encontradas', counters.found],
            ['Enviadas', counters.sent],
            ['Duplicadas', counters.duplicates],
            ['Erros', counters.errors],
        ];
    }

    function createPanel() {
        panel = document.createElement('div');
        panel.style.cssText = [
            'position:fixed;right:12px;bottom:12px;z-index:2147483647;',
            'background:rgba(15,23,42,0.92);color:#e2e8f0;',
            'font:12px/1.5 system-ui,sans-serif;padding:10px 12px;border-radius:8px;',
            'box-shadow:0 2px 12px rgba(0,0,0,0.35);max-width:230px;',
        ].join('');

        const titleRow = document.createElement('div');
        titleRow.style.cssText = 'display:flex;align-items:center;gap:6px;font-weight:600;margin-bottom:6px;';

        const dot = document.createElement('span');
        dot.style.cssText = 'width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;';
        dot.setAttribute('aria-hidden', 'true');

        const title = document.createElement('span');
        title.textContent = 'Jobora Job Capture';

        const status = document.createElement('span');
        status.textContent = 'ativo';
        status.style.cssText = 'color:#4ade80;font-weight:400;';

        const close = document.createElement('button');
        close.textContent = '✕';
        close.style.cssText = 'margin-left:auto;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:12px;';
        close.title = 'Ocultar painel (o script continua)';
        close.addEventListener('click', () => {
            panelVisible = false;
            panel.style.display = 'none';
        });

        titleRow.append(dot, title, status, close);

        const body = document.createElement('div');
        body.style.cssText = 'display:grid;grid-template-columns:auto auto;gap:2px 12px;justify-content:start;';
        body.setAttribute('id', 'vagas-capture-counters');

        panel.append(titleRow, body);
        (document.body || document.documentElement).appendChild(panel);
        updatePanel();
    }

    function updatePanel() {
        if (!panel) return;
        const body = panel.querySelector('#vagas-capture-counters');
        body.replaceChildren();
        for (const [label, value] of counterLabels()) {
            const l = document.createElement('span');
            l.textContent = label;
            const v = document.createElement('span');
            v.textContent = String(value);
            v.style.cssText = 'text-align:right;font-variant-numeric:tabular-nums;';
            body.append(l, v);
        }
    }

    // -------------------------------------------------------------- FLOW

    async function processCard(el) {
        if (processedElements.has(el)) return;
        processedElements.add(el);
        counters.found++;
        updatePanel();

        const jk = el.getAttribute('data-jk');
        const titleEl = el.querySelector('span[title]');
        const cardTitle = (titleEl && (titleEl.getAttribute('title') || titleEl.textContent.trim()))
            || (el.querySelector('h2')?.textContent.trim() || null);

        if (!jk) {
            counters.errors++;
            updatePanel();
            return;
        }

        if (sentIds.has(jk)) {
            counters.duplicates++;
            updatePanel();
            return;
        }

        el.click();
        const paneOk = await waitForPane(jk, cardTitle);

        if (!paneOk) {
            counters.errors++;
            console.error(`[JOBORA] erro ao abrir vaga ${jk}: painel não carregou`);
            updatePanel();
            return;
        }

        const raw = await extractJob(jk, cardTitle, el);

        // Captura sem descrição quebraria o fluxo de promoção (JobPromotionService
        // rejeita abaixo de MIN_DESCRIPTION_LENGTH) — nunca enviar parcial.
        if (!raw || !raw.description) {
            counters.errors++;
            console.error(`[JOBORA] erro ao extrair vaga ${jk}: descrição vazia`);
            updatePanel();
            return;
        }

        const result = await sendCapture(buildPayload(raw));

        if (result.ok) {
            sentIds.add(jk);
            if (result.duplicate) {
                counters.duplicates++;
            } else {
                counters.sent++;
            }
        } else {
            counters.errors++;
        }
        updatePanel();
    }

    function isCard(el) {
        return el.matches('a[data-jk]') && el.getClientRects().length > 0;
    }

    let scanRunning = false;
    let rescanQueued = false;

    async function processNewCards() {
        if (scanRunning) {
            rescanQueued = true;
            return;
        }
        scanRunning = true;
        try {
            const cards = Array.from(document.querySelectorAll('a[data-jk]')).filter(isCard);
            // Lote estritamente sequencial — o painel lateral é estado compartilhado
            // e batches concorrentes associariam descrições às vagas erradas.
            for (const el of cards) {
                await processCard(el);
            }
        } finally {
            scanRunning = false;
            if (rescanQueued) {
                rescanQueued = false;
                processNewCards();
            }
        }
    }

    // ------------------------------------------------------------ OBSERVER

    let observer = null;
    let debounceTimer = null;

    function startObserver() {
        observer = new MutationObserver(() => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(processNewCards, CONFIG.observerDebounceMs);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    // --------------------------------------------------------------- MENU

    function registerMenu() {
        if (typeof GM_registerMenuCommand !== 'function') return;

        GM_registerMenuCommand('Definir URL da API', () => {
            const value = prompt('URL base da API (sem barra final):', CONFIG.apiBaseUrl);
            if (value === null) return;
            CONFIG.apiBaseUrl = value.trim().replace(/\/$/, '');
            storeSet('apiBaseUrl', CONFIG.apiBaseUrl);
        });

        GM_registerMenuCommand('Definir token da API', () => {
            const value = prompt('Token da API do Jobora (VAGAS_API_TOKEN):', CONFIG.apiToken);
            if (value === null) return;
            CONFIG.apiToken = value.trim();
            storeSet('apiToken', CONFIG.apiToken);
        });

        GM_registerMenuCommand('Limpar token', () => {
            CONFIG.apiToken = '';
            storeSet('apiToken', '');
        });
    }

    // ---------------------------------------------------------------- INIT

    function init() {
        if (!location.pathname.startsWith('/jobs')) return;
        if (observer) return; // idempotente — evita painel duplicado se re-executado

        createPanel();
        processNewCards();
        startObserver();
        registerMenu();

        // Debug/teste — não usado pelo fluxo.
        window.__vagasCapture = { counters, CONFIG };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
