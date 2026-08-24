// ==UserScript==
// @name         Jobora Job Capture — LinkedIn → API
// @namespace    vagas.local
// @version      0.1.0
// @description  Captura publicações de vagas da página de resultados de conteúdo do LinkedIn e envia à API do Jobora para revisão manual no painel.
// @match        https://www.linkedin.com/search/results/content/*
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
 * 2. Abrir qualquer página de busca de conteúdo do LinkedIn
 * 3. No menu do script (ícone do Violentmonkey), definir:
 *    - "Definir URL da API": http://localhost:8000 (padrão; o painel Jobora rodando com `php artisan serve`)
 *    - "Definir token da API": o valor de VAGAS_API_TOKEN do .env do Jobora
 *
 * AVISO: se a API estiver hospedada em outro host, adicione `// @connect <host>` no metadata
 * acima — o Violentmonkey bloqueia requisições cross-origin sem o @connect correspondente.
 *
 * O script NÃO faz scraping automático: apenas observa a página aberta, extrai as publicações
 * visíveis (inclusive carregadas por infinite scroll) e as envia para revisão manual no painel.
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
     * Expande o texto truncado ("...ver mais") da publicação, se houver.
     * Única interação DOM permitida; no máximo 1 tentativa por publicação.
     */
    async function expandPost(postEl) {
        const buttons = postEl.querySelectorAll('button[aria-expanded="false"]');
        for (const btn of buttons) {
            const label = ((btn.getAttribute('aria-label') || '') + ' ' + (btn.textContent || '')).trim();
            if (/mais|more/i.test(label)) {
                btn.click();
                await new Promise((r) => setTimeout(r, 120));
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------- EXTRACTION (fonte-específica: LinkedIn)

    function cleanHref(href) {
        return href ? href.split('?')[0].split('#')[0] : null;
    }

    async function extractLinkedInPost(postEl) {
        const link = postEl.querySelector('a[href*="/feed/update/"]');
        const href = link ? link.getAttribute('href') : '';
        const url = cleanHref(href);

        let externalId = null;
        const urn = href.match(/urn:li:activity:(\d+)/);
        if (urn) {
            externalId = 'urn:li:activity:' + urn[1];
        } else {
            const legacy = href.match(/activity-(\d+)/);
            if (legacy) externalId = 'urn:li:activity:' + legacy[1];
        }

        await expandPost(postEl);

        const textBox = postEl.querySelector('[data-testid="expandable-text-box"]');
        let description = toText(textBox);
        if (!textBox) {
            // ponytail: fallback degradado — texto cru do card até o seletor primário ser ajustado
            description = toText(postEl);
        }

        const authorLink = postEl.querySelector('a[href*="/in/"]');
        let author = null;
        if (authorLink) {
            const profileUrl = cleanHref(authorLink.getAttribute('href'));
            const img = authorLink.querySelector('img');
            const name = (img && img.alt) ? img.alt : authorLink.textContent.trim();
            author = { name: name || null, profileUrl };
        }

        // title/company/location: não existem de forma confiável em resultados de
        // conteúdo — não inventar dados (§8 do pedido).
        return { url, externalId, description, author };
    }

    // -------------------------------------------------------------- PAYLOAD

    function buildPayload(raw) {
        const author = raw.author && (raw.author.name || raw.author.profileUrl)
            ? { name: raw.author.name || null, profile_url: raw.author.profileUrl || null }
            : null;

        return {
            source: 'linkedin',
            external_id: raw.externalId,
            url: raw.url,
            search_url: window.location.href,
            title: null,
            description: raw.description,
            company: null,
            location: null,
            author,
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
    const sentIds = new Set(); // externalId já enviado nesta página

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

    async function processPost(el) {
        if (processedElements.has(el)) return;
        processedElements.add(el);
        counters.found++;
        updatePanel();

        const raw = await extractLinkedInPost(el);

        if (!raw.externalId && !raw.description && !raw.url) {
            counters.errors++;
            updatePanel();
            return;
        }

        if (raw.externalId && sentIds.has(raw.externalId)) {
            counters.duplicates++;
            updatePanel();
            return;
        }

        const result = await sendCapture(buildPayload(raw));

        if (result.ok && result.duplicate) {
            counters.duplicates++;
            if (raw.externalId) sentIds.add(raw.externalId);
        } else if (result.ok && !result.duplicate) {
            counters.sent++;
            if (raw.externalId) sentIds.add(raw.externalId);
        } else {
            counters.errors++;
        }
        updatePanel();
    }

    function isPost(el) {
        return el.matches('[role="listitem"]')
            && (el.querySelector('a[href*="/feed/update/"]') || el.querySelector('[data-testid="expandable-text-box"]'));
    }

    async function processNewPosts() {
        const posts = Array.from(document.querySelectorAll('[role="listitem"]')).filter(isPost);
        // Lote sequencial — sem paralelismo desnecessário.
        for (const el of posts) {
            await processPost(el);
        }
    }

    // ------------------------------------------------------------ OBSERVER

    let observer = null;
    let debounceTimer = null;

    function startObserver() {
        observer = new MutationObserver(() => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(processNewPosts, CONFIG.observerDebounceMs);
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
        if (!location.pathname.startsWith('/search/results/content')) return;
        if (observer) return; // idempotente — evita painel duplicado se re-executado

        createPanel();
        processNewPosts();
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
