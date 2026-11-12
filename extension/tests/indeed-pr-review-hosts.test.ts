// @vitest-environment happy-dom
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { isIndeedRoute } from '@/sites/indeed/extract';

describe('Indeed hosts suportados pelo backend', () => {
  it.each(['br.indeed.com', 'www.indeed.com', 'indeed.com'])('aceita HTTPS em %s', (host) => {
    expect(isIndeedRoute(new URL(`https://${host}/jobs?q=php`))).toBe(true);
  });

  it.each([
    'https://de.indeed.com/jobs?q=php',
    'https://uk.indeed.com/jobs?q=php',
    'https://br.indeed.com.evil.test/jobs',
    'http://br.indeed.com/jobs',
    'https://br.indeed.com/viewjob?jk=1',
  ])('não ativa captura para %s', (url) => {
    expect(isIndeedRoute(new URL(url))).toBe(false);
  });

  it('restringe registro aos mesmos hosts sem wildcard', () => {
    const entrypoint = readFileSync('entrypoints/indeed.content/index.ts', 'utf8');
    expect(entrypoint).not.toContain("'https://*.indeed.com/*'");
    for (const host of ['br.indeed.com', 'www.indeed.com', 'indeed.com']) {
      expect(entrypoint).toContain(`'https://${host}/*'`);
    }
  });
});
