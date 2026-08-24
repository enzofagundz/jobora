import { toText } from '@/utils/to-text';
import type { CapturePayload } from '@/types/capture';

/** Casa qualquer subdomínio do Indeed na rota /jobs (filtro fino é o pathname). */
export const INDEED_ROUTE = new MatchPattern('https://*.indeed.com/jobs*');

export const OBSERVER_DEBOUNCE_MS = 400;
const PANE_WAIT_TIMEOUT_MS = 12_000;
const PANE_POLL_MS = 150;
const EXPAND_WAIT_MS = 150;

const PANE_SELECTOR = '#jobsearch-ViewjobPaneWrapper';

export function isIndeedRoute(url: URL | Location): boolean {
  return url.pathname.startsWith('/jobs');
}

/** Card visível: âncora com data-jk e caixas de layout não vazias. */
export function isCard(el: Element): boolean {
  return el.matches('a[data-jk]') && el.getClientRects().length > 0;
}

/**
 * Espera o painel lateral carregar a vaga clicada. `vjk` da URL é a chave
 * primária; fallback compara título do cabeçalho com o do card. Polling de
 * 150 ms até 12 s. Resolve false quando a geração morre (rota trocou,
 * controller desativado) ou no timeout — nenhum scan antigo espera em vão.
 */
export function waitForPane(
  generation: number,
  isGenerationActive: (generation: number) => boolean,
  jk: string,
  cardTitle: string | null,
): Promise<boolean> {
  const { promise, resolve } = Promise.withResolvers<boolean>();
  const started = Date.now();

  const timer = setInterval(() => {
    if (!isGenerationActive(generation)) {
      clearInterval(timer);
      resolve(false);
      return;
    }

    const pane = document.querySelector(PANE_SELECTOR);
    const vjk = new URLSearchParams(location.search).get('vjk');

    const vjkMatch = vjk === jk;
    const title = pane
      ? pane.querySelector('[data-testid="jobsearch-JobInfoHeader-title"]')
      : null;
    const titleText = title ? title.textContent.trim() : '';
    const titleMatch = !!cardTitle && titleText.length > 0 && titleText === cardTitle;

    const desc = document.querySelector('#jobDescriptionText');
    const hasDescription = desc !== null && desc.textContent.trim().length > 0;

    if (hasDescription && (vjkMatch || titleMatch)) {
      clearInterval(timer);
      resolve(true);
      return;
    }

    if (Date.now() - started > PANE_WAIT_TIMEOUT_MS) {
      clearInterval(timer);
      resolve(false);
    }
  }, PANE_POLL_MS);

  return promise;
}

/** Expande "...ver mais" no painel: até 2 tentativas, 150 ms entre elas. */
export async function expandPane(paneEl: Element): Promise<void> {
  for (let attempt = 0; attempt < 2; attempt++) {
    const buttons = paneEl.querySelectorAll<HTMLButtonElement>(
      'button[aria-expanded="false"]',
    );
    let clicked = false;
    for (const btn of buttons) {
      const label = `${btn.getAttribute('aria-label') ?? ''} ${btn.textContent ?? ''}`.trim();
      if (/mais|more/i.test(label)) {
        btn.click();
        clicked = true;
        break;
      }
    }
    if (!clicked) return;
    await new Promise((resolve) => setTimeout(resolve, EXPAND_WAIT_MS));
  }
}

export interface IndeedRawJob {
  jk: string;
  url: string;
  title: string | null;
  description: string;
  company: string | null;
  location: string | null;
}

export async function extractJob(
  jk: string,
  cardTitle: string | null,
  cardEl: Element,
): Promise<IndeedRawJob | null> {
  const pane = document.querySelector(PANE_SELECTOR);
  if (!pane) return null;

  await expandPane(pane);

  const q = (selector: string): string | null => {
    const el = pane.querySelector(selector);
    return el ? el.textContent.trim() : null;
  };

  const title = q('[data-testid="jobsearch-JobInfoHeader-title"]') || cardTitle;
  const company =
    q('[data-testid="inlineHeader-companyName"]') ||
    cardEl.querySelector('[data-testid="company-name"]')?.textContent.trim() ||
    null;
  const jobLocation =
    q('[data-testid="inlineHeader-companyLocation"]') ||
    cardEl.querySelector('[data-testid="text-location"]')?.textContent.trim() ||
    null;
  const description = toText(document.querySelector('#jobDescriptionText'));

  return {
    jk,
    url: window.location.origin + '/viewjob?jk=' + jk,
    title,
    description,
    company,
    location: jobLocation,
  };
}

/**
 * URL da busca congelada por entrada na rota — o clique na vaga acrescenta
 * `?vjk=<jk>`, então `location.href` no envio estaria errado (userscript usa
 * regex idêntica na inicialização).
 */
export function frozenSearchUrl(): string {
  return window.location.href.replace(/[?&]vjk=[^&]*/, '');
}

export function buildPayload(raw: IndeedRawJob, searchUrl: string): CapturePayload {
  return {
    source: 'indeed',
    external_id: raw.jk,
    url: raw.url,
    search_url: searchUrl,
    title: raw.title,
    description: raw.description,
    company: raw.company,
    location: raw.location,
    captured_at: new Date().toISOString(),
  };
}
