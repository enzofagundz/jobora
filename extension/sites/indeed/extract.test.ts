// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import {
  buildPayload,
  extractJob,
  frozenSearchUrl,
  isCard,
} from './extract';

function fixture(): string {
  return readFileSync('tests/fixtures/indeed.html', 'utf8');
}

describe('Indeed extração (fixture própria)', () => {
  beforeEach(() => {
    document.documentElement.innerHTML = fixture().replace(
      /<script[\s\S]*?<\/script>/g,
      '',
    );
  });

  it('isCard: âncoras com data-jk; visibilidade decide por getClientRects', () => {
    const anchors = Array.from(document.querySelectorAll('a'));
    // happy-dom não calcula layout: simula o browser real escondendo o card.
    const hidden = anchors.find((a) => a.getAttribute('data-jk') === 'ghi333')!;
    Object.defineProperty(hidden, 'getClientRects', { value: () => [] });

    const cards = anchors.filter(isCard);
    expect(cards.map((c) => c.getAttribute('data-jk'))).toEqual(['abc111', 'def222']);
  });

  it('título do card: span[title] primeiro, h2 como fallback', () => {
    const anchors = Array.from(document.querySelectorAll('a'));
    // happy-dom não calcula layout: simula o browser real escondendo o card.
    const hidden = anchors.find((a) => a.getAttribute('data-jk') === 'ghi333')!;
    Object.defineProperty(hidden, 'getClientRects', { value: () => [] });
    const cards = anchors.filter(isCard);
    const titleOf = (el: Element): string | null =>
      el.querySelector('span[title]')?.getAttribute('title') ??
      el.querySelector('h2')?.textContent.trim() ??
      null;
    expect(titleOf(cards[0]!)).toBe('Desenvolvedor PHP');
    expect(titleOf(cards[1]!)).toBe('QA Automático');
  });

  it('extractJob: painel com título/empresa/local inline e descrição serializada', async () => {
    const raw = await extractJob(
      'abc111',
      'Desenvolvedor PHP',
      document.querySelector('a[data-jk="abc111"]')!,
    );

    expect(raw).not.toBeNull();
    expect(raw!.jk).toBe('abc111');
    expect(raw!.url).toBe(location.origin + '/viewjob?jk=abc111');
    expect(raw!.title).toBe('Desenvolvedor PHP');
    expect(raw!.company).toBe('Vaga Tech Ltda');
    // Lista serializada com "- " (whitespace entre itens colapsa, como no userscript).
    expect(raw!.description).toContain('- Laravel');
    expect(raw!.description).toContain('- PostgreSQL');
    expect(raw!.description).toContain('Descrição completa da vaga de PHP.');
  });

  it('fallback de empresa/localização/título vem do card quando o painel não tem', async () => {
    document.querySelector('[data-testid="inlineHeader-companyName"]')?.remove();
    document.querySelector('[data-testid="inlineHeader-companyLocation"]')?.remove();
    document.querySelector('[data-testid="jobsearch-JobInfoHeader-title"]')?.remove();

    const cardB = document.querySelector('a[data-jk="def222"]')!;
    const raw = await extractJob('def222', 'QA Automático', cardB);
    expect(raw!.title).toBe('QA Automático'); // fallback: título do card
    expect(raw!.company).toBe('Empresa do Card');
    expect(raw!.location).toBe('Remoto');
  });

  it('sem painel não extrai (retorna null)', async () => {
    document.querySelector('#jobsearch-ViewjobPaneWrapper')?.remove();
    const raw = await extractJob(
      'abc111',
      null,
      document.querySelector('a[data-jk="abc111"]')!,
    );
    expect(raw).toBeNull();
  });

  it('search_url congelada remove vjk e preserva o resto da query', () => {
    window.history.replaceState(null, '', '/jobs?q=php&vjk=abc111&l=Remoto');
    expect(frozenSearchUrl()).toBe('http://localhost:3000/jobs?q=php&l=Remoto');

    window.history.replaceState(null, '', '/jobs?q=php');
    expect(frozenSearchUrl()).toBe('http://localhost:3000/jobs?q=php');

    // vjk no meio sem "&" precedente também é removida.
    window.history.replaceState(null, '', '/jobs?vjk=zzz');
    expect(frozenSearchUrl()).toBe('http://localhost:3000/jobs');
  });

  it('payload: source indeed, external_id = jk, sem autor', () => {
    const p = buildPayload(
      {
        jk: 'abc111',
        url: location.origin + '/viewjob?jk=abc111',
        title: 'Dev PHP',
        description: 'desc',
        company: 'Vaga Tech',
        location: 'SP',
      },
      '/jobs?q=php',
    );
    expect(p.source).toBe('indeed');
    expect(p.external_id).toBe('abc111');
    expect(p.author).toBeUndefined();
    expect(p.search_url).toBe('/jobs?q=php');
    expect(p.title).toBe('Dev PHP');
  });
});
