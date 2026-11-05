// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureEnvelope, CaptureMessage } from '@/types/capture';
import { createLinkedInController } from '@/sites/linkedin/controller';

const SEARCH_URL =
  'https://www.linkedin.com/search/results/content/?keywords=php&datePosted=%5B%22past-week%22%5D';

function cardHtml(urn: number, description = `Vaga ${urn} para desenvolvedor PHP.`): string {
  return `
    <article role="listitem">
      <div class="author">
        <a href="https://www.linkedin.com/in/autor${urn}">Autor ${urn}</a>
      </div>
      <div data-testid="expandable-text-box"><p>${description}</p></div>
      <a href="https://www.linkedin.com/feed/update/urn:li:activity:${urn}?trk=x">Ver publicação</a>
    </article>`;
}

function mountFeed(count: number): HTMLElement {
  document.body.innerHTML = `<div class="feed">${Array.from(
    { length: count },
    (_, i) => cardHtml(i + 1),
  ).join('')}</div>`;
  return document.querySelector('.feed') as HTMLElement;
}

type SendMessage = typeof browser.runtime.sendMessage;

function setupMessaging(runId = 'exec-1') {
  const envelopes: CaptureEnvelope[] = [];
  const contexts: string[] = [];
  const kinds: string[] = [];
  const sendMessage = vi
    .spyOn(fakeBrowser.runtime, 'sendMessage')
    .mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      kinds.push(message.type);
      if (message.type === 'capture:resolve-run') {
        return {
          ok: true,
          run: {
            executionId: runId,
            source: 'linkedin',
            surface: 'linkedin_publications',
            initialUrl: SEARCH_URL,
            expiresAt: null,
          },
          context: 'ctx-test',
        };
      }
      if (message.type === 'capture:submit-envelope') {
        envelopes.push(message.envelope);
        contexts.push(message.context);
        return {
          ok: true,
          duplicate: false,
          status: 201,
          captureId: envelopes.length,
          executionId: message.envelope.capture.execution_id,
          coverage: message.envelope.capture.coverage.status,
          replay: false,
        };
      }
      return { ok: true, duplicate: false };
    });
  return { sendMessage, envelopes, contexts, kinds };
}

async function advance(ms = 0): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms);
}

async function activate(controller: ReturnType<typeof createLinkedInController>) {
  const ctx = new ContentScriptContext(`test-linkedin-envelope-${Math.random()}`);
  const active = controller.activate(ctx);
  // Expansão de "ver mais" usa 120 ms; espera o scan inicial assentar.
  await advance(200);
  await active;
}

describe('LinkedIn publicações — envelope consolidado', () => {
  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    window.happyDOM.setURL(SEARCH_URL);
    vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
    vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('envia um único envelope v1 após o scroll, sem requests legados por post', async () => {
    mountFeed(2);
    const { envelopes, contexts, kinds } = setupMessaging();
    const controller = createLinkedInController();

    await activate(controller);
    await advance(1200 * 8);

    expect(kinds.filter((kind) => kind === 'capture:submit')).toEqual([]);
    expect(envelopes).toHaveLength(1);
    expect(contexts).toEqual(['ctx-test']);
    const envelope = envelopes[0]!;
    expect(envelope.format).toBe('jobora.capture-envelope');
    expect(envelope.version).toBe(1);
    expect(envelope.capture).toMatchObject({
      execution_id: 'exec-1',
      source: 'linkedin',
      surface: 'linkedin_publications',
      initial_url: SEARCH_URL,
      coverage: {
        status: 'complete',
        reason: null,
        blocks_expected: 2,
        blocks_captured: 2,
      },
    });
    expect(envelope.blocks.map((block) => block.kind)).toEqual(['post', 'post']);
    expect(envelope.blocks.map((block) => block.platform_id)).toEqual([
      'urn:li:activity:1',
      'urn:li:activity:2',
    ]);
    expect(envelope.blocks.every((block) => block.permalink.startsWith('https://'))).toBe(true);
    expect(envelope.capture.html).toContain('data-capture-surface="linkedin_publications"');
    expect(envelope.capture.html.match(/data-block-id=/g)).toHaveLength(2);
    expect(envelope.capture.html).not.toMatch(/<(script|style|iframe|form|button|img)\b/i);
    expect(envelope.capture.html).not.toMatch(/\son[a-z]+\s*=/i);
    expect(envelope.capture.html).not.toMatch(/\sstyle\s*=/i);
    expect(envelope.capture.html).not.toMatch(/(?:javascript|data:text\/html):/i);

    const parsed = new DOMParser().parseFromString(envelope.capture.html, 'text/html');
    const roots = parsed.querySelectorAll('[data-capture-surface], [data-capture-version]');
    expect(roots).toHaveLength(1);
    expect(roots[0]!.getAttribute('data-capture-surface')).toBe('linkedin_publications');
    expect(roots[0]!.getAttribute('data-capture-version')).toBe('1');
    for (const block of envelope.blocks) {
      const nodes = parsed.querySelectorAll(`[data-block-id="${block.block_id}"]`);
      expect(nodes).toHaveLength(1);
      expect(nodes[0]!.getAttribute('data-kind')).toBe(block.kind);
      expect(nodes[0]!.getAttribute('data-platform-id')).toBe(block.platform_id);
      expect(nodes[0]!.getAttribute('data-permalink')).toBe(block.permalink);
    }
    expect(controller.getCaptureState()).toMatchObject({
      captured: true,
      coverage: 'complete',
      executionId: 'exec-1',
    });

    controller.deactivate();
    await advance(0);
    expect(envelopes).toHaveLength(1);
  });

  it('Esc durante o scroll produz parcial cancelled explícita', async () => {
    mountFeed(3);
    const { envelopes } = setupMessaging();
    const controller = createLinkedInController();

    await activate(controller);
    await advance(1200);
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await advance(1200 * 2);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.capture.coverage).toMatchObject({
      status: 'partial',
      reason: 'cancelled',
      blocks_expected: null,
      blocks_captured: 3,
    });
    expect(controller.getCaptureState()).toMatchObject({ captured: true, coverage: 'partial' });

    controller.deactivate();
    await advance(1200 * 8);
    expect(envelopes).toHaveLength(1);
  });

  it('só declara captura após ACK do backend, nunca enquanto o POST está pendente', async () => {
    mountFeed(2);
    let release!: (value: unknown) => void;
    const gate = new Promise((resolve) => {
      release = resolve;
    });
    const envelopes: CaptureEnvelope[] = [];
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(
      async (raw: unknown): Promise<any> => {
        const message = raw as CaptureMessage;
        if (message.type === 'capture:resolve-run') {
          return {
            ok: true,
            run: {
              executionId: 'exec-1',
              source: 'linkedin',
              surface: 'linkedin_publications',
              initialUrl: SEARCH_URL,
              expiresAt: null,
            },
            context: 'ctx-test',
          };
        }
        if (message.type === 'capture:submit-envelope') {
          envelopes.push(message.envelope);
          await gate;
          return { ok: true, status: 201, coverage: 'complete' };
        }
        return { ok: true };
      },
    );

    const controller = createLinkedInController();
    await activate(controller);
    await advance(1200 * 8);

    expect(envelopes).toHaveLength(1);
    expect(controller.getCaptureState()).toMatchObject({ captured: false, pending: true });

    release(undefined);
    await advance(0);
    expect(controller.getCaptureState()).toMatchObject({ captured: true, pending: false });

    controller.deactivate();
    await advance(0);
  });

  it('SPA troca de busca resolve nova execução e não mistura a anterior', async () => {
    mountFeed(1);
    const envelopes: CaptureEnvelope[] = [];
    let run = 0;
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(
      async (raw: unknown): Promise<any> => {
        const message = raw as CaptureMessage;
        if (message.type === 'capture:resolve-run') {
          run++;
          return {
            ok: true,
            run: {
              executionId: `exec-${run}`,
              source: 'linkedin',
              surface: 'linkedin_publications',
              initialUrl: message.url,
              expiresAt: null,
            },
            context: 'ctx-test',
          };
        }
        if (message.type === 'capture:submit-envelope') {
          envelopes.push(message.envelope);
          return { ok: true, status: 201, coverage: 'complete' };
        }
        return { ok: true };
      },
    );

    const controller = createLinkedInController();
    await activate(controller);
    await advance(1200 * 8);
    controller.deactivate();
    await advance(0);

    const secondUrl =
      'https://www.linkedin.com/search/results/content/?keywords=qa&datePosted=%5B%22past-week%22%5D';
    window.happyDOM.setURL(secondUrl);
    document.body.innerHTML = `<div class="feed">${cardHtml(9, 'Vaga QA.')}</div>`;

    await activate(controller);
    await advance(1200 * 8);
    controller.deactivate();
    await advance(0);

    expect(envelopes).toHaveLength(2);
    expect(envelopes[0]!.capture).toMatchObject({
      execution_id: 'exec-1',
      initial_url: SEARCH_URL,
    });
    expect(envelopes[1]!.capture).toMatchObject({
      execution_id: 'exec-2',
      initial_url: secondUrl,
    });
    expect(envelopes[1]!.blocks.map((block) => block.platform_id)).toEqual([
      'urn:li:activity:9',
    ]);
  });

  it('remove conteúdo executável e atributos perigosos antes do envelope', async () => {
    document.body.innerHTML = `<div class="feed">
      <article role="listitem">
        <script>window.__pwned = true</script>
        <div data-testid="expandable-text-box" style="color:red" onclick="alert(1)">
          <p>Vaga PHP.</p>
          <img src="https://tracker.example/pixel" onerror="alert(2)">
          <a href="javascript:alert(3)">link hostil</a>
        </div>
        <a href="https://www.linkedin.com/feed/update/urn:li:activity:501">Ver</a>
        <button aria-expanded="false" style="display:none">ver mais</button>
      </article>
    </div>`;
    const { envelopes } = setupMessaging();
    const controller = createLinkedInController();

    await activate(controller);
    await advance(1200 * 8);

    expect(envelopes).toHaveLength(1);
    const html = envelopes[0]!.capture.html;
    expect(html).not.toMatch(/<script|<img|<button/i);
    expect(html).not.toMatch(/\son[a-z]+\s*=|javascript:|style=/i);

    controller.deactivate();
    await advance(0);
  });

  it('acumula bloco que o DOM virtualizado removeu antes do observer processar', async () => {
    mountFeed(1);
    const { envelopes } = setupMessaging();
    const controller = createLinkedInController();

    await activate(controller);
    await advance(1000);

    const feed = document.querySelector('.feed') as HTMLElement;
    feed.insertAdjacentHTML('beforeend', cardHtml(777, 'Vaga removida pelo virtual scroll.'));
    const transient = feed.lastElementChild as HTMLElement;
    transient.remove();

    await advance(400);
    await advance(1200 * 8);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks.map((block) => block.platform_id)).toEqual([
      'urn:li:activity:1',
      'urn:li:activity:777',
    ]);
    expect(envelopes[0]!.capture.html).toContain('data-platform-id="urn:li:activity:777"');

    controller.deactivate();
    await advance(0);
  });
});
