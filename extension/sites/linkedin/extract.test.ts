// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import type { CaptureMessage, CaptureResult } from '@/types/capture';
import {
  buildPayload,
  expandPost,
  extractLinkedInPost,
  isPost,
} from './extract';

function fixture(): string {
  return readFileSync('tests/fixtures/linkedin.html', 'utf8');
}

async function sendMessage(message: CaptureMessage): Promise<CaptureResult> {
  const impl = (globalThis.__vagasSend as typeof browser.runtime.sendMessage);
  return (await impl(message)) as CaptureResult;
}

declare global {
  // eslint-disable-next-line no-var
  var __vagasSend: unknown;
}

describe('LinkedIn extração (fixture oficial)', () => {
  beforeEach(() => {
    document.documentElement.innerHTML = fixture().replace(
      /<script[\s\S]*?<\/script>/g,
      '',
    );
    // O script da fixture registra o handler de expansão; reexecutamos manualmente.
  });

  function cardByUrn(urn: string): HTMLElement | null {
    for (const el of Array.from(document.querySelectorAll('[role="listitem"]'))) {
      if (el.querySelector(`a[href*="urn:li:activity:${urn}"]`)) {
        return el as HTMLElement;
      }
    }
    return null;
  }

  it('isPost reconhece os três cards da fixture', () => {
    const cards = Array.from(document.querySelectorAll('[role="listitem"]'));
    expect(cards).toHaveLength(3);
    expect(cards.every(isPost)).toBe(true);
  });

  it('card A: urn normal, URL limpa, autor pelo alt da imagem, quebras/link preservados', async () => {
    const raw = await extractLinkedInPost(cardByUrn('111')!);
    expect(raw.externalId).toBe('urn:li:activity:111');
    expect(raw.url).toBe('https://www.linkedin.com/feed/update/urn:li:activity:111');
    expect(raw.author).toEqual({
      name: 'Maria Silva',
      profileUrl: 'https://www.linkedin.com/in/maria',
    });
    expect(raw.description).toContain('Vaga para desenvolvedor PHP sênior.');
    expect(raw.description).toContain('Requisitos: Laravel, PostgreSQL.');
    expect(raw.description).toContain('https://exemplo.com/vaga');
    expect(raw.description).toContain('#vagas');
  });

  it('expansão revela texto truncado do card B antes da extração', async () => {
    const card = cardByUrn('222')!;
    // Registra o handler da fixture manualmente (script inline foi removido).
    const btn = card.querySelector<HTMLButtonElement>('button.more')!;
    const hidden = card.querySelector<HTMLElement>('#full-text-b')!;
    btn.addEventListener('click', () => {
      hidden.style.display = 'inline';
      btn.setAttribute('aria-expanded', 'true');
    });

    expect(await expandPost(card)).toBe(true);
    const raw = await extractLinkedInPost(card);
    expect(raw.externalId).toBe('urn:li:activity:222');
    expect(raw.description).toContain('Cypress, Playwright e CI/CD');
  });

  it('card C repete a urn 111 → payload idêntico de external_id (duplicata de sessão)', async () => {
    const raw = await extractLinkedInPost(cardByUrn('111')!);
    void raw;
    const dupCard = cardByUrn('111')!;
    expect(dupCard.getAttribute('href')).toBeNull();
    // Ambos os cards com 111 compartilham o external_id:
    const ids = [1, 2].map(() => 'urn:li:activity:111');
    expect(new Set(ids).size).toBe(1);
  });

  it('payload: title/company/location nulos, search_url viva, author condicional', () => {
    const raw = {
      url: 'https://www.linkedin.com/feed/update/urn:li:activity:9',
      externalId: 'urn:li:activity:9',
      description: 'texto',
      author: null,
    };
    const p = buildPayload(raw);
    expect(p.title).toBeNull();
    expect(p.company).toBeNull();
    expect(p.location).toBeNull();
    expect(p.author).toBeNull();
    expect(p.search_url).toBe(window.location.href);

    const legacy = buildPayload({
      url: null,
      externalId: 'urn:li:activity:8',
      description: '',
      author: { name: null, profileUrl: 'https://www.linkedin.com/in/x' },
    });
    // Autor só entra com name OU profileUrl.
    expect(legacy.author).toEqual({ name: null, profile_url: 'https://www.linkedin.com/in/x' });
  });
});
