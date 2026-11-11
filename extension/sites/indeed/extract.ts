import { toText } from '@/utils/to-text';

export const OBSERVER_DEBOUNCE_MS = 400;
const PANE_WAIT_TIMEOUT_MS = 12_000;
const PANE_POLL_MS = 150;
const EXPAND_WAIT_MS = 150;

const PANE_SELECTOR = '#jobsearch-ViewjobPaneWrapper';

export function isIndeedRoute(url: URL | Location): boolean {
  return url.protocol === 'https:'
    && ['br.indeed.com', 'www.indeed.com', 'indeed.com'].includes(url.hostname)
    && url.pathname.startsWith('/jobs');
}

/** Card visível: âncora com data-jk e caixas de layout não vazias. */
export function isCard(el: Element): boolean {
  return el.matches('a[data-jk]') && el.getClientRects().length > 0;
}

/**
 * Espera o painel lateral carregar a vaga clicada. Correlaciona pelo `jk`
 * explícito do painel ou pelo `vjk` selecionado na URL. Polling de 150 ms até
 * 12 s. Resolve false quando a geração morre (rota trocou,
 * controller desativado) ou no timeout — nenhum scan antigo espera em vão.
 */
export function waitForPane(
  generation: number,
  isGenerationActive: (generation: number) => boolean,
  jk: string,
  previousVjk: string | null,
  previousPane: Element | null,
  previousPaneHtml: string | null,
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
    const desc = pane?.querySelector('#jobDescriptionText') ?? null;
    const hasDescription = desc !== null && desc.textContent.trim().length > 0;

    if (
      pane &&
      hasDescription &&
      indeedPaneMatches(pane, jk, previousVjk, previousPane, previousPaneHtml)
    ) {
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

/** Use Indeed's explicit jk marker when present; otherwise require selected vjk. */
export function indeedPaneMatches(
  pane: Element,
  jk: string,
  previousVjk: string | null = null,
  previousPane: Element | null = null,
  previousPaneHtml: string | null = null,
): boolean {
  const candidates = [pane, ...pane.querySelectorAll('[data-jk]')];
  for (const candidate of candidates) {
    const paneJk = candidate.getAttribute('data-jk')?.trim();
    if (paneJk) return paneJk === jk;
  }

  for (const link of pane.querySelectorAll<HTMLAnchorElement>('a[href*="jk="]')) {
    try {
      const paneJk = new URL(link.href, location.href).searchParams.get('jk');
      if (paneJk) return paneJk === jk;
    } catch {
      // Ignore malformed platform links and use selected-card identity below.
    }
  }

  return (
    new URLSearchParams(location.search).get('vjk') === jk &&
    (previousVjk === jk || pane !== previousPane || pane.innerHTML !== previousPaneHtml)
  );
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
  const description = toText(pane.querySelector('#jobDescriptionText'));

  return {
    jk,
    url: `${window.location.origin}/viewjob?jk=${encodeURIComponent(jk)}`,
    title,
    description,
    company,
    location: jobLocation,
  };
}

/** Return search URL without the selected-job `vjk` parameter. */
export function frozenSearchUrl(): string {
  const url = new URL(window.location.href);
  url.searchParams.delete('vjk');
  return url.toString();
}
