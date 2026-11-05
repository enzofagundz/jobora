// @vitest-environment happy-dom
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureEnvelope, CaptureMessage } from '@/types/capture';
import { createLinkedInController } from '@/sites/linkedin/controller';
import { createHydrationIdentityCollector } from '@/sites/linkedin/hydration-identity';
import { hydrationScript, mountHydrationScript } from './helpers/hydration-fixture';

const SEARCH_URL = 'https://www.linkedin.com/search/results/content/?keywords=exemplo';
const POST_A = '9000000000000000001';
const POST_B = '9000000000000000002';

function fixture(): string {
  return readFileSync('tests/fixtures/linkedin-publications-null-identity.html', 'utf8');
}

describe('LinkedIn publicações sem permalink/URN no DOM', () => {
  let envelopes: CaptureEnvelope[];

  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    window.happyDOM.setURL(SEARCH_URL);
    document.documentElement.innerHTML = fixture();

    envelopes = [];
    vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
    vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') {
        return {
          ok: true,
          run: {
            executionId: 'exec-null-identity',
            source: 'linkedin',
            surface: 'linkedin_publications',
            initialUrl: SEARCH_URL,
            expiresAt: null,
          },
          context: 'ctx-null-identity',
        };
      }
      if (message.type === 'capture:submit-envelope') {
        envelopes.push(message.envelope);
        return { ok: true, status: 201, coverage: message.envelope.capture.coverage.status };
      }
      return { ok: true, duplicate: false };
    });
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  async function activate(controller: ReturnType<typeof createLinkedInController>): Promise<void> {
    const ctx = new ContentScriptContext(`test-linkedin-hydration-${Math.random()}`);
    const active = controller.activate(ctx);
    await vi.advanceTimersByTimeAsync(2000);
    await active;
    await vi.advanceTimersByTimeAsync(9000);
  }

  it('preenche ID/permalink reais via hidratação e ignora a linha global de IDs', async () => {
    mountHydrationScript(
      hydrationScript([
        { componentKey: 'ck-A', activityId: POST_A },
        { componentKey: 'ck-B', activityId: POST_B },
      ]),
    );
    const identities = createHydrationIdentityCollector();
    const controller = createLinkedInController({
      resolveHydrationIdentity: (componentKey) => identities.resolve(componentKey),
    });

    await activate(controller);

    expect(envelopes).toHaveLength(1);
    const envelope = envelopes[0]!;
    expect(envelope.blocks).toHaveLength(2);
    expect(envelope.blocks.map((block) => block.platform_id)).toEqual([
      `urn:li:activity:${POST_A}`,
      `urn:li:activity:${POST_B}`,
    ]);
    expect(envelope.blocks.map((block) => block.permalink)).toEqual([
      `https://www.linkedin.com/feed/update/urn:li:activity:${POST_A}`,
      `https://www.linkedin.com/feed/update/urn:li:activity:${POST_B}`,
    ]);
    expect(envelope.capture.html).toContain(`data-platform-id="urn:li:activity:${POST_A}"`);
    expect(envelope.capture.html).toContain(
      `data-permalink="https://www.linkedin.com/feed/update/urn:li:activity:${POST_B}"`,
    );
    expect(envelope.capture.coverage).toMatchObject({
      status: 'complete',
      reason: null,
      blocks_captured: 2,
    });
    expect(controller.getCaptureState()).toMatchObject({ captured: true, blocks: 2 });

    controller.deactivate();
  });

  it('reset com script presente mantém IDs para nova navegação/token', async () => {
    mountHydrationScript(
      hydrationScript([
        { componentKey: 'ck-A', activityId: POST_A },
        { componentKey: 'ck-B', activityId: POST_B },
      ]),
    );
    const identities = createHydrationIdentityCollector();
    const controller = createLinkedInController({
      resolveHydrationIdentity: (componentKey) => identities.resolve(componentKey),
    });

    await activate(controller);
    expect(envelopes[0]!.blocks).toHaveLength(2);
    controller.deactivate();
    await vi.advanceTimersByTimeAsync(0);

    identities.reset();
    envelopes.length = 0;
    window.happyDOM.setURL(`${SEARCH_URL}&pagina=2`);
    const restarted = createLinkedInController({
      resolveHydrationIdentity: (componentKey) => identities.resolve(componentKey),
    });

    await activate(restarted);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks.map((block) => block.platform_id)).toEqual([
      `urn:li:activity:${POST_A}`,
      `urn:li:activity:${POST_B}`,
    ]);
    restarted.deactivate();
  });

  it('reset do mapa não reutiliza ID em nova navegação SPA', async () => {
    mountHydrationScript(
      hydrationScript([
        { componentKey: 'ck-A', activityId: POST_A },
        { componentKey: 'ck-B', activityId: POST_B },
      ]),
    );
    const identities = createHydrationIdentityCollector();
    const controller = createLinkedInController({
      resolveHydrationIdentity: (componentKey) => identities.resolve(componentKey),
    });

    await activate(controller);
    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks).toHaveLength(2);
    controller.deactivate();
    await vi.advanceTimersByTimeAsync(0);

    identities.reset();
    envelopes.length = 0;
    window.happyDOM.setURL(`${SEARCH_URL}&pagina=2`);
    document.documentElement.innerHTML = fixture();
    document.querySelectorAll('[role="listitem"]').forEach((element, index) => {
      element.setAttribute('componentkey', index === 0 ? 'ck-C' : 'ck-D');
    });

    await activate(controller);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks).toEqual([]);
    expect(envelopes[0]!.capture.coverage).toMatchObject({
      status: 'partial',
      reason: 'timeout',
    });
    expect(envelopes[0]!.capture.html).not.toContain(POST_A);
    controller.deactivate();
  });

  it('sem mapping não inventa ID e envia parcial timeout, não no_content', async () => {
    const identities = createHydrationIdentityCollector();
    const controller = createLinkedInController({
      resolveHydrationIdentity: (componentKey) => identities.resolve(componentKey),
    });

    await activate(controller);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks).toEqual([]);
    expect(envelopes[0]!.capture.coverage).toMatchObject({
      status: 'partial',
      reason: 'timeout',
      blocks_captured: 0,
    });
    expect(controller.getCaptureState()).toMatchObject({
      captured: true,
      coverage: 'partial',
      blocks: 0,
    });

    controller.deactivate();
  });
});
