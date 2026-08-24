// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import type { CapturePayload } from '@/types/capture';
import {
  deriveCaptureIdentity,
  externalCaptureIdentity,
} from './capture-identity';
import { createCaptureSession } from './capture-session';

function payload(overrides: Partial<CapturePayload> = {}): CapturePayload {
  return {
    source: 'linkedin',
    external_id: null,
    url: null,
    search_url: 'https://www.linkedin.com/search/results/content/?keywords=vaga',
    title: null,
    description: 'Vaga para desenvolvedor PHP com Laravel.',
    company: null,
    location: null,
    author: { name: 'Maria Silva', profile_url: 'https://www.linkedin.com/in/maria' },
    captured_at: '2026-08-18T10:00:00Z',
    ...overrides,
  };
}

describe('capture-identity', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  it('mesmo external_id em elementos diferentes → mesma identidade', async () => {
    const a = await deriveCaptureIdentity(payload({ external_id: 'urn:li:activity:111' }));
    const b = await deriveCaptureIdentity(
      payload({ external_id: 'urn:li:activity:111', description: 'outro texto' }),
    );
    expect(a).toBe(b);
    expect(a).toBe('external:linkedin:urn:li:activity:111');
  });

  it('URL com query/fragment/tracking → mesma identidade canônica', async () => {
    const a = await deriveCaptureIdentity(
      payload({ url: 'https://www.linkedin.com/feed/update/urn:li:activity:9/' }),
    );
    const b = await deriveCaptureIdentity(
      payload({
        url: 'https://www.linkedin.com/feed/update/urn:li:activity:9/?lipi=abc&trk=x#frag',
        description: 'texto diferente',
      }),
    );
    expect(a).toBe(b);
    expect(a).toBe('url:linkedin:https://www.linkedin.com/feed/update/urn:li:activity:9/');
  });

  it('fingerprint igual para payloads com whitespace/caixa diferentes', async () => {
    const a = await deriveCaptureIdentity(payload());
    const b = await deriveCaptureIdentity(
      payload({
        description: '  VAGA   para Desenvolvedor\nPHP com Laravel.  ',
        author: { name: ' MARIA   silva ', profile_url: 'https://www.linkedin.com/in/maria ' },
      }),
    );
    expect(a).toBe(b);
    expect(a.startsWith('fingerprint:linkedin:')).toBe(true);
  });

  it('descrições iguais com autor/empresa/local diferentes NÃO colidem', async () => {
    const a = await deriveCaptureIdentity(payload());
    const b = await deriveCaptureIdentity(
      payload({
        company: 'Acme',
        location: 'Remoto',
        author: { name: 'João Souza', profile_url: 'https://www.linkedin.com/in/joao' },
      }),
    );
    expect(a).not.toBe(b);
  });

  it('captured_at/search_url não entram no fingerprint', async () => {
    const a = await deriveCaptureIdentity(payload());
    const b = await deriveCaptureIdentity(
      payload({
        captured_at: '2026-08-19T23:59:59Z',
        search_url: 'https://www.linkedin.com/search/results/content/?keywords=outra',
      }),
    );
    expect(a).toBe(b);
  });
});

describe('capture-session (regressão 11 cards)', () => {
  let session: ReturnType<typeof createCaptureSession>;

  beforeEach(() => {
    fakeBrowser.reset();
    session = createCaptureSession();
  });

  it('11 identidades reivindicadas uma vez; clones/recriações ignorados; 12º card capturado', () => {
    const identities = Array.from({ length: 11 }, (_, i) => `external:linkedin:urn:${i}`);

    // Scan inicial: 11 claims.
    for (const id of identities) expect(session.claim(id)).toBe(true);
    expect(session.counters.found).toBe(11);

    // Observer dispara de novo: mesmos 11 elementos recriados (novas refs DOM,
    // mesmas identidades) + scroll + re-render → nenhum claim novo.
    for (const id of [...identities, ...identities]) expect(session.claim(id)).toBe(false);
    expect(session.counters.found).toBe(11);

    // Um 12º card aparece.
    expect(session.claim('external:linkedin:urn:12th')).toBe(true);
    expect(session.counters.found).toBe(12);
  });

  it('contadores: found=processed=requests lógicos; resultado só uma vez por identidade', () => {
    const ids = Array.from({ length: 11 }, (_, i) => `external:linkedin:urn:${i}`);
    for (const id of ids) session.claim(id);

    // 10 criadas + 1 duplicada do backend.
    for (let i = 0; i < 10; i++) session.applyResult(ids[i], { ok: true, duplicate: false });
    session.applyResult(ids[10], { ok: true, duplicate: true, status: 200 });

    expect(session.counters).toMatchObject({
      found: 11,
      processed: 11,
      sent: 10,
      duplicates: 1,
      errors: 0,
    });

    // Resultado repetido (clone que passou antes do claim?) é ignorado.
    session.applyResult(ids[0], { ok: true, duplicate: false });
    expect(session.counters.processed).toBe(11);
    expect(session.counters.sent).toBe(10);
  });

  it('erro é terminal na sessão: sem reenvio automático, claim vira processado', () => {
    session.claim('external:linkedin:urn:x');
    session.applyResult('external:linkedin:urn:x', { ok: false, error: 'network' });

    expect(session.counters.errors).toBe(1);
    expect(session.counters.processed).toBe(1);
    // Re-tentativa do mesmo elemento/identidade: bloqueada pelo claim consumido.
    expect(session.claim('external:linkedin:urn:x')).toBe(false);
    expect(session.counters.found).toBe(1);
  });

  it('applyResult ignora identidade nunca reivindicada', () => {
    session.applyResult('fantasma', { ok: true, duplicate: false });
    expect(session.counters.sent).toBe(0);
    expect(session.counters.processed).toBe(0);
  });

  it('WeakSet cobre apenas elementos inválidos, sem virar identidade', () => {
    const el = document.createElement('div');
    expect(session.isInvalidElement(el)).toBe(false);
    session.markInvalidElement(el);
    expect(session.isInvalidElement(el)).toBe(true);
    // Elemento válido reivindicado não tem relação com o WeakSet.
    expect(session.claim(externalCaptureIdentity('indeed', 'jk1'))).toBe(true);
  });
});
