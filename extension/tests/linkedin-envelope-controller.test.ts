// @vitest-environment happy-dom
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureEnvelope, CaptureMessage } from '@/types/capture';
import { createLinkedInController } from '@/sites/linkedin/controller';
import { OBSERVER_DEBOUNCE_MS } from '@/sites/linkedin/extract';

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
    expect(
      envelope.blocks.every(
        (block) => block.kind === 'post' && block.permalink?.startsWith('https://') === true,
      ),
    ).toBe(true);
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
      if (block.platform_id === null) {
        expect(nodes[0]!.hasAttribute('data-platform-id')).toBe(false);
      } else {
        expect(nodes[0]!.getAttribute('data-platform-id')).toBe(block.platform_id);
      }
      if (block.permalink === null) {
        expect(nodes[0]!.hasAttribute('data-permalink')).toBe(false);
      } else {
        expect(nodes[0]!.getAttribute('data-permalink')).toBe(block.permalink);
      }
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

  it('preserva conteúdo com e sem identidade no envelope, inclusive post removido do DOM', async () => {
    document.documentElement.innerHTML = readFileSync(
      'tests/fixtures/linkedin-publications-content-without-identity.html',
      'utf8',
    );
    const posts = Array.from(document.querySelectorAll('[role="listitem"]'));
    expect(posts).toHaveLength(9);
    expect(document.querySelectorAll('a[href*="/feed/update/"]')).toHaveLength(2);
    expect(document.querySelector('#rehydrate-data')).toBeNull();
    const textOnlyPosts = posts.slice(3);
    expect(textOnlyPosts).toHaveLength(6);
    expect(
      textOnlyPosts.every(
        (post) => !post.hasAttribute('id') && !post.querySelector('[id], [componentkey], a[href]'),
      ),
    ).toBe(true);

    const { envelopes, kinds } = setupMessaging();
    const controller = createLinkedInController();
    await activate(controller);
    await advance(1000);
    posts[8]!.remove();
    await advance(OBSERVER_DEBOUNCE_MS + 100);
    await advance(1200 * 8);

    expect(envelopes).toHaveLength(1);
    expect(kinds.filter((kind) => kind === 'capture:submit')).toEqual([]);
    const envelope = envelopes[0]!;
    expect(envelope.version).toBe(1);
    expect(envelope.capture.surface).toBe('linkedin_publications');
    const parsed = new DOMParser().parseFromString(envelope.capture.html, 'text/html');
    for (let index = 1; index <= 9; index++) {
      const content = `POST_CONTENT_${String(index).padStart(2, '0')}`;
      expect(envelope.capture.html).toContain(content);
      expect(envelope.capture.html.split(content)).toHaveLength(2);

      const node = Array.from(parsed.querySelectorAll('[data-block-id]')).find((candidate) =>
        candidate.textContent?.includes(content),
      );
      expect(node).toBeDefined();
      const block = envelope.blocks.find(
        (candidate) => candidate.block_id === node!.getAttribute('data-block-id'),
      );
      expect(block).toBeDefined();

      if (index === 1) {
        expect(block).toMatchObject({
          kind: 'post',
          platform_id: 'urn:li:activity:9100000000000000001',
          permalink: 'https://www.linkedin.com/feed/update/urn:li:activity:9100000000000000001',
        });
      } else if (index === 2) {
        expect(block).toMatchObject({
          kind: 'post',
          platform_id: 'urn:li:activity:9100000000000000002',
          permalink: 'https://www.linkedin.com/feed/update/urn:li:activity:9100000000000000002',
        });
      } else if (index === 3) {
        expect(block).toMatchObject({
          kind: 'post',
          platform_id: null,
          permalink: 'https://www.linkedin.com/feed/update/url-only-post',
        });
      } else {
        expect(node!.hasAttribute('data-platform-id')).toBe(false);
        expect(node!.hasAttribute('data-permalink')).toBe(false);
        expect(block).toMatchObject({ kind: 'post', platform_id: null, permalink: null });
      }
    }
    expect(envelope.capture.coverage).toMatchObject({
      status: 'complete',
      reason: null,
      blocks_captured: 9,
    });

    controller.deactivate();
    await advance(0);
  });

  it('mantém textos iguais em posts distintos sem identidade forte', async () => {
    document.body.innerHTML = `
      <div class="feed">
        <article role="listitem"><div data-testid="expandable-text-box"><p>POST_DUPLICATE_CONTENT</p></div></article>
        <article role="listitem"><div data-testid="expandable-text-box"><p>POST_DUPLICATE_CONTENT</p></div></article>
        <article role="listitem">
          <a href="https://www.linkedin.com/in/autor-a">Autor A</a>
          <div data-testid="expandable-text-box"><p>POST_SHARED_AUTHOR_CONTENT</p></div>
        </article>
        <article role="listitem">
          <a href="https://www.linkedin.com/in/autor-b">Autor B</a>
          <div data-testid="expandable-text-box"><p>POST_SHARED_AUTHOR_CONTENT</p></div>
        </article>
      </div>`;
    const { envelopes } = setupMessaging();
    const controller = createLinkedInController();

    await activate(controller);
    await advance(1200 * 8);

    expect(envelopes).toHaveLength(1);
    const envelope = envelopes[0]!;
    const parsed = new DOMParser().parseFromString(envelope.capture.html, 'text/html');
    for (const content of ['POST_DUPLICATE_CONTENT', 'POST_SHARED_AUTHOR_CONTENT']) {
      const nodes = Array.from(parsed.querySelectorAll('[data-block-id]')).filter((node) =>
        node.textContent?.includes(content),
      );
      expect(nodes).toHaveLength(2);
      for (const node of nodes) {
        expect(node.hasAttribute('data-platform-id')).toBe(false);
        expect(node.hasAttribute('data-permalink')).toBe(false);
        const block = envelope.blocks.find(
          (candidate) => candidate.block_id === node.getAttribute('data-block-id'),
        );
        expect(block).toMatchObject({ kind: 'post', platform_id: null, permalink: null });
      }
    }
    expect(envelope.blocks).toHaveLength(4);
    expect(envelope.capture.coverage).toMatchObject({ status: 'complete', reason: null });

    controller.deactivate();
    await advance(0);
  });

  it('busca sem cards no primeiro scan não finaliza como vazia', async () => {
    document.body.innerHTML = '<div class="feed"></div>';
    const { envelopes } = setupMessaging();
    const controller = createLinkedInController();

    const active = controller.activate(new ContentScriptContext(`test-empty-${Math.random()}`));
    await advance(200);
    await active;

    // LinkedIn renderiza os resultados depois do document_start.
    await advance(800);
    const feed = document.querySelector('.feed') as HTMLElement;
    feed.insertAdjacentHTML('beforeend', cardHtml(1));
    await advance(OBSERVER_DEBOUNCE_MS + 100);
    await advance(1200 * 4);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks).toHaveLength(1);
    expect(envelopes[0]!.capture.coverage).toMatchObject({ status: 'complete', reason: null });

    controller.deactivate();
    await advance(0);
  });

  it('card que chega na janela de settle ainda entra no envelope', async () => {
    mountFeed(1);
    const { envelopes } = setupMessaging();
    const controller = createLinkedInController();
    const ctx = new ContentScriptContext(`test-settle-${Math.random()}`);

    const active = controller.activate(ctx);
    // Interação do usuário dispensa o auto-scroll: resta só a janela de settle.
    window.dispatchEvent(new Event('wheel', { bubbles: true }));
    await advance(200);
    await active;

    await advance(1200);
    const feed = document.querySelector('.feed') as HTMLElement;
    feed.insertAdjacentHTML('beforeend', cardHtml(9));
    await advance(OBSERVER_DEBOUNCE_MS + 100);
    await advance(1200 * 4);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks).toHaveLength(2);
    expect(envelopes[0]!.capture.html).toContain('Vaga 9 para desenvolvedor PHP.');

    controller.deactivate();
    await advance(0);
  });

  it('captura novo conteúdo quando DOM virtualizado reutiliza mesmo elemento', async () => {
    document.body.innerHTML = `
      <div class="feed">
        <article role="listitem">
          <a href="https://www.linkedin.com/in/autor-a">Autor A</a>
          <div data-testid="expandable-text-box"><p>RECYCLED_POST_A</p></div>
        </article>
      </div>`;
    const { envelopes } = setupMessaging();
    const controller = createLinkedInController();
    await activate(controller);

    const feed = document.querySelector('.feed') as HTMLElement;
    const post = feed.querySelector('[role="listitem"]') as HTMLElement;
    await advance(1000);
    post.innerHTML = `
      <a href="https://www.linkedin.com/in/autor-b">Autor B</a>
      <div data-testid="expandable-text-box"><p>RECYCLED_POST_B</p></div>`;
    feed.removeChild(post);
    feed.appendChild(post);
    await advance(OBSERVER_DEBOUNCE_MS + 100);

    feed.removeChild(post);
    feed.appendChild(post);
    await advance(OBSERVER_DEBOUNCE_MS + 100);
    await advance(1200 * 8);

    expect(envelopes).toHaveLength(1);
    const html = envelopes[0]!.capture.html;
    expect(html).toContain('RECYCLED_POST_A');
    expect(html).toContain('RECYCLED_POST_B');
    expect(html.split('RECYCLED_POST_A')).toHaveLength(2);
    expect(html.split('RECYCLED_POST_B')).toHaveLength(2);
    expect(envelopes[0]!.blocks).toHaveLength(2);

    controller.deactivate();
    await advance(0);
  });
});
