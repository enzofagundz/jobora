import { cleanHref, toText } from '@/utils/to-text';
import type { CapturePayload } from '@/types/capture';

export const LINKEDIN_JOBS_ROUTE = new MatchPattern(
  'https://www.linkedin.com/jobs/search/*',
);

export const OBSERVER_DEBOUNCE_MS = 400;
const PANE_WAIT_TIMEOUT_MS = 12_000;
const PANE_POLL_MS = 150;
const EXPAND_WAIT_MS = 150;

const PANE_SELECTOR = '.scaffold-layout__detail.jobs-search__job-details';
const DESCRIPTION_SELECTOR = '#job-details';

export function isLinkedInJobsRoute(url: URL | Location): boolean {
  return LINKEDIN_JOBS_ROUTE.includes(url);
}

export function isJobCard(el: Element): boolean {
  if (el.hasAttribute('data-occludable-job-id')) return true;
  if (el.querySelector('[data-job-id]')) return true;
  if (el.hasAttribute('data-job-id')) return true;
  const link = el.querySelector('a[href*="/jobs/view/"]');
  if (link && /\/jobs\/view\/\d+/.test(link.getAttribute('href') ?? '')) return true;
  return false;
}

export function jobIdFromCard(cardEl: Element): string | null {
  const inner = cardEl.querySelector('[data-job-id]')?.getAttribute('data-job-id');
  if (inner && /^\d+$/.test(inner.trim())) return inner.trim();

  const selfId = (cardEl as HTMLElement).getAttribute?.('data-job-id');
  if (selfId && /^\d+$/.test(selfId.trim())) return selfId.trim();

  const occludable = cardEl.getAttribute('data-occludable-job-id');
  if (occludable && /^\d+$/.test(occludable.trim())) return occludable.trim();

  const closest = cardEl.closest?.('[data-occludable-job-id]')?.getAttribute('data-occludable-job-id');
  if (closest && /^\d+$/.test(closest.trim())) return closest.trim();

  const link = cardEl.querySelector('a[href*="/jobs/view/"]')?.getAttribute('href');
  if (link) {
    const viewMatch = link.match(/\/jobs\/view\/(\d+)/);
    if (viewMatch?.[1]) return viewMatch[1];
  }

  // fallback: any href inside card
  for (const anchor of cardEl.querySelectorAll('a[href]')) {
    const href = anchor.getAttribute('href') ?? '';
    const viewMatch = href.match(/\/jobs\/view\/(\d+)/);
    if (viewMatch?.[1]) return viewMatch[1];
  }

  return null;
}

export function frozenSearchUrl(): string {
  const url = new URL(window.location.href);
  url.searchParams.delete('currentJobId');
  url.searchParams.delete('position');
  url.searchParams.delete('pageNum');
  return url.toString();
}

export function waitForJobsPane(
  generation: number,
  isGenerationActive: (generation: number) => boolean,
  jobId: string,
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
    const currentJobId = new URLSearchParams(window.location.search).get('currentJobId');

    const idMatch = currentJobId === jobId;
    const titleEl = pane?.querySelector('h1 a, .job-details-jobs-unified-top-card__job-title a, .job-details-jobs-unified-top-card__job-title');
    const titleText = titleEl ? (titleEl.textContent ?? '').trim() : '';
    const titleMatch = !!cardTitle && titleText.length > 0 && titleText === cardTitle;

    if (idMatch || titleMatch) {
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

export async function expandJobsPane(paneEl: Element): Promise<void> {
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

export interface LinkedInJobsRaw {
  jobId: string;
  url: string;
  title: string | null;
  description: string;
  company: string | null;
  location: string | null;
}

export async function extractLinkedInJob(
  jobId: string,
  cardEl: Element,
): Promise<LinkedInJobsRaw | null> {
  const pane = document.querySelector(PANE_SELECTOR);
  if (!pane) return null;

  await expandJobsPane(pane);

  const queryPaneText = (selector: string): string | null => {
    const el = pane.querySelector(selector);
    return el ? (el.textContent ?? '').trim() || null : null;
  };

  const linkEl = (cardEl.querySelector('a.job-card-container__link') ??
    cardEl.querySelector('a[href*="/jobs/view/"]')) as HTMLAnchorElement | null;
  const cardTitle = linkEl?.getAttribute('aria-label')?.trim()
    ?? linkEl?.querySelector('strong')?.textContent?.trim()
    ?? linkEl?.textContent?.trim()
    ?? null;

  const paneTitle = queryPaneText('.job-details-jobs-unified-top-card__job-title a')
    ?? queryPaneText('.job-details-jobs-unified-top-card__job-title')
    ?? queryPaneText('h1 a');

  const title = paneTitle || cardTitle;

  const company = queryPaneText('.job-details-jobs-unified-top-card__company-name')
    ?? queryPaneText('.job-details-jobs-unified-top-card__company-name a')
    ?? cardEl.querySelector('.artdeco-entity-lockup__subtitle')?.textContent?.trim()
    ?? cardEl.querySelector('.artdeco-entity-lockup__subtitle span')?.textContent?.trim()
    ?? null;

  const locationFromCard = cardEl.querySelector('.job-card-container__metadata-wrapper li span')?.textContent?.trim()
    ?? cardEl.querySelector('.job-card-container__metadata-wrapper li')?.textContent?.trim()
    ?? null;
  const locationFromPane = queryPaneText('.job-details-jobs-unified-top-card__primary-description-container .tvm__text')
    ?? queryPaneText('.job-details-jobs-unified-top-card__primary-description-container')
    ?? null;
  const location = locationFromCard ?? locationFromPane;

  const descriptionEl = document.querySelector(DESCRIPTION_SELECTOR);
  const description = toText(descriptionEl);
  if (!description) return null;

  const url = `https://www.linkedin.com/jobs/view/${jobId}`;

  return {
    jobId,
    url,
    title,
    description,
    company,
    location,
  };
}

export function buildJobsPayload(raw: LinkedInJobsRaw, searchUrl: string): CapturePayload {
  return {
    source: 'linkedin',
    external_id: `linkedin:job:${raw.jobId}`,
    url: raw.url,
    search_url: searchUrl,
    title: raw.title,
    description: raw.description,
    company: raw.company,
    location: raw.location,
    author: null,
    captured_at: new Date().toISOString(),
  };
}
