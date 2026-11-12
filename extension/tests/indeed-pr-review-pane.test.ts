// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { indeedDescriptionElement, indeedPaneMatches, indeedPaneMetadata } from '@/sites/indeed/extract';

beforeEach(() => window.happyDOM.setURL('https://br.indeed.com/jobs?vjk=target'));
afterEach(() => {
  document.body.innerHTML = '';
  window.happyDOM.setURL('http://localhost:3000');
});

function pane(html: string, jk?: string): Element {
  document.body.innerHTML = `<section id="pane"${jk ? ` data-jk="${jk}"` : ''}>${html}</section>`;
  return document.querySelector('#pane')!;
}

describe('Indeed identidade do painel', () => {
  it('examina todos os marcadores antes de rejeitar', () => {
    expect(indeedPaneMatches(pane('<div data-jk="related"></div><div data-jk="target"></div>'), 'target')).toBe(true);
  });

  it('examina todos os links antes de rejeitar', () => {
    expect(indeedPaneMatches(pane('<a href="/viewjob?jk=related">Outra</a><a href="/viewjob?jk=target">Atual</a>'), 'target')).toBe(true);
  });

  it('encontra link alvo mesmo após marcador de vaga relacionada', () => {
    expect(indeedPaneMatches(pane('<div data-jk="related"></div><a href="/viewjob?jk=target">Atual</a>'), 'target')).toBe(true);
  });

  it('identidade explícita do próprio painel prevalece sobre vaga relacionada e URL global', () => {
    expect(indeedPaneMatches(pane('<a href="/viewjob?jk=target">Relacionada</a>', 'other'), 'target')).toBe(false);
  });

  it.each(['<div data-jk="other"></div>', '<a href="/viewjob?jk=other">Outra</a>'])(
    'não usa URL global quando marcadores só apontam outra vaga: %s', (html) => {
      expect(indeedPaneMatches(pane(html), 'target')).toBe(false);
    },
  );

  it('aceita URL global apenas sem identidade explícita e com painel atualizado', () => {
    const current = pane('<p id="jobDescriptionText">Descrição</p>');
    expect(indeedPaneMatches(current, 'target', 'previous', null)).toBe(true);
    expect(indeedPaneMatches(current, 'target', 'previous', 'Descrição', {
      description: indeedDescriptionElement(current), metadata: indeedPaneMetadata(current),
    })).toBe(false);
  });
});
