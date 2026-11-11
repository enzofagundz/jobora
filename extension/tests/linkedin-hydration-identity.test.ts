// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import {
  createHydrationIdentityCollector,
  parseHydrationIdentities,
} from '@/sites/linkedin/hydration-identity';
import { hydrationScript, mountHydrationScript } from './helpers/hydration-fixture';

const POST_A = '9000000000000000001';
const POST_B = '9000000000000000002';

function manualScript(lines: string[]): string {
  return `window.__como_rehydration__ = ${JSON.stringify(lines)};`;
}

describe('hidratação LinkedIn — identidade de publicações', () => {
  afterEach(() => {
    document.documentElement.innerHTML = '<head></head><body></body>';
  });

  it('mapeia componentkey para activityId somente pela referência direta', () => {
    const parsed = parseHydrationIdentities(
      hydrationScript([
        { componentKey: 'ck-A', activityId: POST_A },
        { componentKey: 'ck-B', activityId: POST_B },
      ]),
    );

    expect(parsed.get('ck-A')).toBe(POST_A);
    expect(parsed.get('ck-B')).toBe(POST_B);
    expect(parsed.size).toBe(2);
  });

  it('não mapeia JSON malformado', () => {
    expect(parseHydrationIdentities('window.__como_rehydration__ = [oops').size).toBe(0);
  });

  it('não mapeia referência ambígua com dois IDs de update', () => {
    const script = manualScript([
      '100:["$","$L200",null,{"role":"listitem","componentkey":"ck-X","children":["$L201"]}]',
      `200:["$",null,null,{"feedUpdateUrn":{"activityUrn":{"activityId":"${POST_A}"}},"threadUrn":{"threadUrnActivityThreadUrn":{"activityUrn":{"activityId":"${POST_A}"}}}}]`,
      `201:["$",null,null,{"feedUpdateUrn":{"activityUrn":{"activityId":"${POST_B}"}},"threadUrn":{"threadUrnActivityThreadUrn":{"activityUrn":{"activityId":"${POST_B}"}}}}]`,
    ]);

    expect(parseHydrationIdentities(script).size).toBe(0);
  });

  it('não mapeia quando thread/root não confirma o ID do update', () => {
    const script = manualScript([
      '100:["$","$L200",null,{"role":"listitem","componentkey":"ck-X"}]',
      `200:["$",null,null,{"feedUpdateUrn":{"activityUrn":{"activityId":"${POST_A}"}},"threadUrn":{"threadUrnActivityThreadUrn":{"activityUrn":{"activityId":"${POST_B}"}}}}]`,
    ]);

    expect(parseHydrationIdentities(script).size).toBe(0);
  });

  it('expira identidade após TTL local', () => {
    let now = 0;
    mountHydrationScript(
      hydrationScript([{ componentKey: 'ck-A', activityId: POST_A }]),
    );
    const collector = createHydrationIdentityCollector({ now: () => now, ttlMs: 10 });

    expect(collector.resolve('ck-A')).not.toBeNull();
    now = 11;
    expect(collector.resolve('ck-A')).toBeNull();
    collector.dispose();
  });

  it('reset com script presente relê a hidratação; script removido não ressuscita mapa', async () => {
    const script = mountHydrationScript(
      hydrationScript([{ componentKey: 'ck-A', activityId: POST_A }]),
    );
    const collector = createHydrationIdentityCollector();
    expect(collector.resolve('ck-A')).not.toBeNull();

    collector.reset();
    expect(collector.resolve('ck-A')).not.toBeNull();

    script.remove();
    await new Promise((resolve) => setTimeout(resolve, 0));
    collector.reset();
    expect(collector.resolve('ck-A')).toBeNull();
    collector.dispose();
  });

  it('captura script inserido e removido no mesmo tick, antes de body existir', async () => {
    document.body.remove();
    const collector = createHydrationIdentityCollector();
    const script = mountHydrationScript(
      hydrationScript([{ componentKey: 'ck-A', activityId: POST_A }]),
    );
    script.remove();

    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(collector.resolve('ck-A')).toEqual({
      activityId: POST_A,
      permalink: `https://www.linkedin.com/feed/update/urn:li:activity:${POST_A}`,
    });
    collector.reset();
    expect(collector.resolve('ck-A')).toBeNull();
    collector.dispose();
  });
});
