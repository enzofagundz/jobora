// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { cleanHref, toText } from '@/utils/to-text';

describe('toText', () => {
  it('retorna vazio sem root', () => {
    expect(toText(null)).toBe('');
  });

  it('serializa blocos, BR e listas como o userscript', () => {
    const el = document.createElement('div');
    el.innerHTML = '<p>Vaga PHP.</p><br><ul><li>Laravel</li><li>PostgreSQL</li></ul>';
    // P vira \n…\n, BR vira \n, cada LI vira "- …\n"; quebras colapsadas para no máx. 2.
    expect(toText(el)).toBe('Vaga PHP.\n\n- Laravel\n- PostgreSQL');
  });

  it('links usam só o texto visível', () => {
    const el = document.createElement('div');
    el.innerHTML = '<a href="https://exemplo.com?a=1">aplicar aqui</a>';
    expect(toText(el)).toBe('aplicar aqui');
  });

  it('colapsa espaços horizontais e preserva quebras', () => {
    const el = document.createElement('div');
    el.innerHTML = '<p>a   b\tc</p><p>d</p>';
    expect(toText(el)).toBe('a b c\n\nd');
  });

  it('trim final', () => {
    const el = document.createElement('div');
    el.innerHTML = '<p>  texto  </p>';
    expect(toText(el)).toBe('texto');
  });
});

describe('cleanHref', () => {
  it('corta query e fragment', () => {
    expect(cleanHref('https://x.com/feed/update/urn:li:activity:111?lipi=abc#f')).toBe(
      'https://x.com/feed/update/urn:li:activity:111',
    );
  });

  it('null/undefined viram null', () => {
    expect(cleanHref(null)).toBeNull();
    expect(cleanHref(undefined)).toBeNull();
  });
});
