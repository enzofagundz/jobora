// @vitest-environment happy-dom
import { describe, expect, it, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import {
  buildJobsPayload,
  extractLinkedInJob,
  frozenSearchUrl,
  isJobCard,
  isLinkedInJobsRoute,
  jobIdFromCard,
  jobsPaneMatches,
} from './extract';

function fixture(): string {
  return readFileSync('tests/fixtures/linkedin-jobs.html', 'utf8');
}

describe('LinkedIn Jobs extração (fixture real)', () => {
  beforeEach(() => {
    document.documentElement.innerHTML = fixture().replace(
      /<script[\s\S]*?<\/script>/g,
      '',
    );
  });

  it('isLinkedInJobsRoute reconhece /jobs/search/* e rejeita outras rotas', () => {
    expect(isLinkedInJobsRoute(new URL('https://www.linkedin.com/jobs/search/?keywords=Laravel'))).toBe(true);
    expect(isLinkedInJobsRoute(new URL('https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2&currentJobId=4461568021'))).toBe(true);
    expect(isLinkedInJobsRoute(new URL('https://www.linkedin.com/jobs/view/4461568021/'))).toBe(false);
    expect(isLinkedInJobsRoute(new URL('https://www.linkedin.com/search/results/content/?keywords=vaga'))).toBe(false);
    expect(isLinkedInJobsRoute(new URL('https://www.linkedin.com/feed/'))).toBe(false);
  });

  it('recognizes the redirected search-results route without accepting unrelated job paths or origins', () => {
    const redirected = 'https://www.linkedin.com/jobs/search-results?skipRedirect=true&currentJobId=4486639255&keywords=Laravel&f_WT=2&f_SAL=f_SA_id_225001%3A272001&infoNotice=ai-job-search&f_TPR=r86400';
    expect(isLinkedInJobsRoute(new URL(redirected))).toBe(true);
    window.happyDOM.setURL(redirected);
    expect(isLinkedInJobsRoute(window.location)).toBe(true);
    expect(isLinkedInJobsRoute(new URL('https://www.linkedin.com/jobs/search-results/'))).toBe(true);
    for (const url of [
      'https://www.linkedin.com/jobs/search-results-extra?keywords=Laravel',
      'https://www.linkedin.com/jobs/search-results/other',
      'https://www.linkedin.com/jobs/collections/recommended/',
      'https://www.linkedin.com/jobs/view/4486639255/',
      'https://linkedin.com/jobs/search-results?keywords=Laravel',
      'https://other.example/jobs/search-results?keywords=Laravel',
      'http://www.linkedin.com/jobs/search-results?keywords=Laravel',
    ]) expect(isLinkedInJobsRoute(new URL(url))).toBe(false);
    const frozen = new URL(frozenSearchUrl());
    expect(frozen.pathname).toBe('/jobs/search-results');
    expect(frozen.searchParams.has('currentJobId')).toBe(false);
    expect(frozen.searchParams.get('f_SAL')).toBe('f_SA_id_225001:272001');
    expect(frozen.searchParams.get('f_TPR')).toBe('r86400');
    expect(frozen.searchParams.get('f_WT')).toBe('2');
    expect(frozen.searchParams.get('skipRedirect')).toBe('true');
    expect(frozen.searchParams.get('infoNotice')).toBe('ai-job-search');
  });

  it('isJobCard detecta cards com data-job-id ou data-occludable-job-id', () => {
    const cards = Array.from(document.querySelectorAll('li[data-occludable-job-id]'));
    expect(cards.filter(isJobCard)).toHaveLength(4);
    const withJobId = document.querySelector('div[data-job-id="4461568021"]')!;
    expect(isJobCard(withJobId.closest('li')!)).toBe(true);
    const fake = document.createElement('div');
    expect(isJobCard(fake)).toBe(false);
  });

  it('isJobCard aceita card só com href /jobs/view/ quando sem data-*', () => {
    const hrefOnly = document.createElement('div');
    hrefOnly.innerHTML = '<a href="/jobs/view/1234567890/?trk=foo">Vaga href</a>';
    expect(isJobCard(hrefOnly)).toBe(true);

    const plain = document.createElement('div');
    plain.textContent = 'sem link';
    expect(isJobCard(plain)).toBe(false);
  });

  it('jobIdFromCard prioriza data-job-id > occludable > href', () => {
    const card1 = document.querySelector('li[data-occludable-job-id="4461568021"]')!;
    expect(jobIdFromCard(card1)).toBe('4461568021');

    const card2 = document.querySelector('li[data-occludable-job-id="4461568022"]')!;
    expect(jobIdFromCard(card2)).toBe('4461568022');

    const card3 = document.querySelector('li[data-occludable-job-id="4461568023"]')!;
    // sem data-job-id interno, cai para occludable
    expect(jobIdFromCard(card3)).toBe('4461568023');

    const solo = document.createElement('div');
    solo.innerHTML = '<a href="/jobs/view/999888777/?trk=flagship">Vaga</a>';
    expect(jobIdFromCard(solo)).toBe('999888777');

    const empty = document.createElement('div');
    expect(jobIdFromCard(empty)).toBeNull();
  });

  it('frozenSearchUrl remove currentJobId e preserva filtros', () => {
    (window as unknown as { happyDOM: { setURL: (u: string) => void } }).happyDOM.setURL('https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2&currentJobId=4461568021');
    expect(frozenSearchUrl()).toBe('https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2');

    (window as unknown as { happyDOM: { setURL: (u: string) => void } }).happyDOM.setURL('https://www.linkedin.com/jobs/search/?keywords=Laravel&currentJobId=111&position=5');
    const frozen = frozenSearchUrl();
    expect(frozen).not.toContain('currentJobId');
    expect(frozen).not.toContain('position');
    expect(frozen).toContain('keywords=Laravel');

    (window as unknown as { happyDOM: { setURL: (u: string) => void } }).happyDOM.setURL('https://www.linkedin.com/jobs/search/?keywords=PHP');
    expect(frozenSearchUrl()).toBe('https://www.linkedin.com/jobs/search/?keywords=PHP');
  });

  it('extractLinkedInJob extrai painel com título/empresa/local e descrição serializada', async () => {
    const card = document.querySelector('li[data-occludable-job-id="4461568021"]')!;
    const raw = await extractLinkedInJob('4461568021', card);

    expect(raw).not.toBeNull();
    expect(raw!.jobId).toBe('4461568021');
    expect(raw!.url).toBe('https://www.linkedin.com/jobs/view/4461568021');
    expect(raw!.title).toBe('PHP Back End Developer');
    expect(raw!.company).toBe('micro1');
    expect(raw!.location).toBe('América Latina (Remoto)');
    expect(raw!.description).toContain('Job Description');
    expect(raw!.description).toContain('Laravel');
    expect(raw!.description).toContain('- Laravel');
  });

  it('não aceita currentJobId da URL quando o painel não expõe ID/permalink', () => {
    window.happyDOM.setURL(
      'https://www.linkedin.com/jobs/search/?keywords=Laravel&currentJobId=222',
    );
    const pane = document.querySelector('.scaffold-layout__detail.jobs-search__job-details')!;
    pane.removeAttribute('data-job-id');
    pane.querySelectorAll('[data-job-id]').forEach((node) => node.removeAttribute('data-job-id'));
    pane.querySelectorAll('a[href*="/jobs/view/"]').forEach((node) => node.remove());

    expect(jobsPaneMatches('222', pane)).toBe(false);
  });

  it('não aceita painel antigo por título igual; exige ID/permalink explícito', async () => {
    window.happyDOM.setURL(
      'https://www.linkedin.com/jobs/search/?keywords=Laravel&currentJobId=4461568022',
    );
    const pane = document.querySelector('.scaffold-layout__detail.jobs-search__job-details')!;
    const title = pane.querySelector('h1 a')!;
    title.textContent = 'PHP Back End Developer';

    expect(jobsPaneMatches('4461568022', pane)).toBe(false);
    expect(jobsPaneMatches('4461568021', pane)).toBe(true);

    const card = document.querySelector('li[data-occludable-job-id="4461568022"]')!;
    expect(await extractLinkedInJob('4461568022', card)).toBeNull();
  });

  it('extractLinkedInJob retorna null quando painel sem descrição', async () => {
    document.querySelector('.job-details-jobs-unified-top-card__company-name')?.remove();
    document.querySelector('#job-details')?.remove();

    const card = document.querySelector('li[data-occludable-job-id="4461568022"]')!;
    const raw = await extractLinkedInJob('4461568022', card);
    expect(raw).toBeNull();
  });

  it('extractLinkedInJob usa fallback de location do painel quando card sem local', async () => {
    // remove local do card
    document.querySelector('.job-card-container__metadata-wrapper')?.remove();
    const card = document.querySelector('li[data-occludable-job-id="4461568021"]')!;
    const raw = await extractLinkedInJob('4461568021', card);
    expect(raw).not.toBeNull();
    // painel tem <span class="tvm__text">América Latina</span> em .refs, fixture simula com mesmo
    expect(raw!.location).toBe('América Latina');
  });

  it('extractLinkedInJob resolve título duplicado via aria-label', async () => {
    // simula estrutura real de .refs com aria-label e spans duplicados
    const card = document.querySelector('li[data-occludable-job-id="4461568021"]')!;
    const link = card.querySelector('a.job-card-container__link') as HTMLElement;
    link.setAttribute('aria-label', 'PHP Back End Developer');
    link.innerHTML = '<span aria-hidden="true"><strong>PHP Back End Developer</strong></span><span class="visually-hidden">PHP Back End Developer</span>';
    const raw = await extractLinkedInJob('4461568021', card);
    expect(raw!.title).toBe('PHP Back End Developer');
    expect(raw!.title).not.toContain('PHP Back End DeveloperPHP');
  });

  it('buildJobsPayload gera external_id linkedin:job:<id> e author null', () => {
    const raw = {
      jobId: '4461568021',
      url: 'https://www.linkedin.com/jobs/view/4461568021',
      title: 'PHP Back End Developer',
      description: 'Descrição completa',
      company: 'micro1',
      location: 'América Latina (Remoto)',
    };
    const p = buildJobsPayload(raw, 'https://www.linkedin.com/jobs/search/?keywords=Laravel');
    expect(p.source).toBe('linkedin');
    expect(p.external_id).toBe('linkedin:job:4461568021');
    expect(p.url).toBe('https://www.linkedin.com/jobs/view/4461568021');
    expect(p.search_url).toBe('https://www.linkedin.com/jobs/search/?keywords=Laravel');
    expect(p.title).toBe('PHP Back End Developer');
    expect(p.company).toBe('micro1');
    expect(p.location).toBe('América Latina (Remoto)');
    expect(p.description).toBe('Descrição completa');
    expect(p.author).toBeNull();
    expect(p.captured_at).toMatch(/Z$/);
  });
});
