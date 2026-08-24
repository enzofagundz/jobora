import { cleanHref, toText } from '@/utils/to-text';
import type { CapturePayload } from '@/types/capture';

/** Padrão oficial WXT p/ SPA: casa o host inteiro, filtra rota em runtime. */
export const LINKEDIN_ROUTE = new MatchPattern(
  'https://www.linkedin.com/search/results/content/*',
);

export const OBSERVER_DEBOUNCE_MS = 400;
const EXPAND_WAIT_MS = 120;

export function isLinkedInRoute(url: URL | Location): boolean {
  return LINKEDIN_ROUTE.includes(url);
}

/** Card = listitem com link de post ou caixa de texto expansível (isPost). */
export function isPost(el: Element): boolean {
  return (
    el.matches('[role="listitem"]') &&
    (el.querySelector('a[href*="/feed/update/"]') !== null ||
      el.querySelector('[data-testid="expandable-text-box"]') !== null)
  );
}

/** Expande "...ver mais": um clique no primeiro botão que casa /mais|more/i; espera 120 ms. */
export async function expandPost(postEl: Element): Promise<boolean> {
  for (const btn of postEl.querySelectorAll<HTMLButtonElement>(
    'button[aria-expanded="false"]',
  )) {
    const label = `${btn.getAttribute('aria-label') ?? ''} ${btn.textContent ?? ''}`.trim();
    if (/mais|more/i.test(label)) {
      btn.click();
      await new Promise((resolve) => setTimeout(resolve, EXPAND_WAIT_MS));
      return true;
    }
  }
  return false;
}

export interface LinkedInRawPost {
  url: string | null;
  externalId: string | null;
  description: string;
  author: { name: string | null; profileUrl: string | null } | null;
}

/** `urn:li:activity:<n>` ou `activity-<n>` de um href; null se ausente. */
export function activityUrn(href: string | null | undefined): string | null {
  if (!href) return null;
  const urn = href.match(/urn:li:activity:(\d+)/);
  if (urn) return `urn:li:activity:${urn[1]}`;
  const legacy = href.match(/activity-(\d+)/);
  return legacy ? `urn:li:activity:${legacy[1]}` : null;
}

/** Varre atributos do card e descendentes por valores exatamente no padrão URN. */
function activityUrnFromAttributes(rootEl: Element): string | null {
  const pattern = /urn:li:activity:(\d+)|(?<=activity-)(\d+)/;
  for (const el of [rootEl, ...rootEl.querySelectorAll('*')]) {
    for (const attr of el.attributes) {
      const match = attr.value.match(pattern);
      if (match) {
        return `urn:li:activity:${match[1] ?? match[2]}`;
      }
    }
  }
  return null;
}

export async function extractLinkedInPost(postEl: Element): Promise<LinkedInRawPost> {
  const link = postEl.querySelector('a[href*="/feed/update/"]');
  const url = cleanHref(link?.getAttribute('href') ?? null);

  // URN de atividade: primeiro em todos os href do card, depois em qualquer
  // atributo próprio/descendente que case exatamente os padrões conhecidos.
  let externalId: string | null = null;
  for (const a of postEl.querySelectorAll('a[href]')) {
    const id = activityUrn(a.getAttribute('href'));
    if (id) {
      externalId = id;
      break;
    }
  }
  if (!externalId) externalId = activityUrnFromAttributes(postEl);

  await expandPost(postEl);

  const textBox = postEl.querySelector('[data-testid="expandable-text-box"]');
  // ponytail: fallback degradado — texto cru do card até o seletor primário ser ajustado
  const description = textBox ? toText(textBox) : toText(postEl);

  let author: LinkedInRawPost['author'] = null;
  const authorLink = postEl.querySelector('a[href*="/in/"]');
  if (authorLink) {
    const profileUrl = cleanHref(authorLink.getAttribute('href'));
    const img = authorLink.querySelector('img');
    const name = (img && img.alt) || authorLink.textContent.trim();
    author = { name: name || null, profileUrl };
  }

  return { url, externalId, description, author };
}

/** Payload fiel ao userscript: title/company/location nulos, search_url viva no envio. */
export function buildPayload(raw: LinkedInRawPost): CapturePayload {
  const author =
    raw.author && (raw.author.name || raw.author.profileUrl)
      ? { name: raw.author.name, profile_url: raw.author.profileUrl }
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
