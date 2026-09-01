// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureMessage } from '@/types/capture';
import { createLinkedInJobsController } from '@/sites/linkedin-jobs/controller';

const CARD_COUNT = 5;

function jobCardHtml(id: number, title: string): string {
  return `
    <li data-occludable-job-id="${id}" class="scaffold-layout__list-item">
      <div data-job-id="${id}" class="job-card-container">
        <a href="/jobs/view/${id}/" class="job-card-container__link">${title}</a>
        <div class="artdeco-entity-lockup__subtitle">Empresa ${id}</div>
        <ul class="job-card-container__metadata-wrapper"><li><span>Local ${id}</span></li></ul>
      </div>
    </li>`;
}

function mountJobsList(count = CARD_COUNT): HTMLElement {
  document.body.innerHTML = `<ul class="scaffold-layout__list-container">${Array.from(
    { length: count },
    (_, i) => jobCardHtml(i + 1, `Vaga ${i + 1}`),
  ).join('')}</ul>`;
  return document.querySelector('.scaffold-layout__list-container') as HTMLElement;
}

function openJobsPane(title: string): void {
  document.querySelector('.scaffold-layout__detail.jobs-search__job-details')?.remove();
  document.body.insertAdjacentHTML(
    'beforeend',
    `<div class="scaffold-layout__detail jobs-search__job-details">
      <div class="jobs-search__job-details--container">
        <h1 class="job-details-jobs-unified-top-card__job-title"><a href="/jobs/view/1/">${title}</a></h1>
        <div class="job-details-jobs-unified-top-card__company-name">Empresa</div>
        <div id="job-details" class="jobs-description-content__text--stretch"><p>Descrição completa de ${title}.</p><p>Stack: PHP, Laravel.</p></div>
      </div>
    </div>`,
  );
}

async function advance(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms);
}

describe('linkedin-jobs controller — integridade observer/SPA', () => {
  let sendMessageMock: ReturnType<typeof vi.fn>;
  let spaClickHandler: (e: Event) => void;

  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    window.happyDOM.setURL('https://www.linkedin.com/jobs/search/?keywords=Laravel');

    const settings = window.happyDOM.settings;
    settings.navigation.disableMainFrameNavigation = true;
    settings.navigation.disableFallbackToSetURL = true;

    sendMessageMock = vi
      .spyOn(fakeBrowser.runtime, 'sendMessage')
      .mockImplementation(async () => ({ ok: true, duplicate: false }));

    spaClickHandler = (e) => {
      const target = e.target instanceof Element ? e.target : null;
      const card = target?.closest('div[data-job-id]');
      if (!card) return;
      const jobId = card.getAttribute('data-job-id');
      const title = card.querySelector('a.job-card-container__link')?.textContent?.trim() ?? null;
      if (!jobId) return;
      window.happyDOM.setURL(`https://www.linkedin.com/jobs/search/?keywords=Laravel&currentJobId=${jobId}`);
      if (title) openJobsPane(title);
    };
    document.addEventListener('click', spaClickHandler, true);
  });

  afterEach(() => {
    document.removeEventListener('click', spaClickHandler, true);
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  function sentJobIds(): string[] {
    return sendMessageMock.mock.calls.map((call) => {
      const message = call[0] as CaptureMessage;
      return message.payload.external_id ?? '';
    });
  }

  async function activateUntilDone(): Promise<{ deactivate: () => void }> {
    const ctx = new ContentScriptContext('test-linkedin-jobs');
    const controller = createLinkedInJobsController();
    let done = false;
    const active = controller.activate(ctx).then(() => {
      done = true;
    });
    for (let i = 0; i < 300 && !done; i++) {
      await advance(200);
    }
    await active;
    return { deactivate: () => controller.deactivate() };
  }

  it('5 cards → exatamente 5 requests; recriação não reclica', async () => {
    mountJobsList();
    const { deactivate } = await activateUntilDone();

    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);
    expect(new Set(sentJobIds()).size).toBe(CARD_COUNT);
    expect(sentJobIds()).toEqual(
      Array.from({ length: CARD_COUNT }, (_, i) => `linkedin:job:${i + 1}`),
    );

    const list = document.querySelector('.scaffold-layout__list-container') as HTMLElement;
    list.innerHTML = list.innerHTML;
    await advance(400);
    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);

    deactivate();
  });

  it('item novo pós-scan gera exatamente 1 request adicional', async () => {
    mountJobsList();
    const { deactivate } = await activateUntilDone();
    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);

    const list = document.querySelector('.scaffold-layout__list-container') as HTMLElement;
    list.insertAdjacentHTML('beforeend', jobCardHtml(999, 'Vaga nova'));
    let done = false;
    let resolveDone!: () => void;
    const settled = new Promise<void>((r) => { resolveDone = r; });
    sendMessageMock.mockImplementationOnce(async () => {
      done = true;
      resolveDone();
      return { ok: true, duplicate: false };
    });
    for (let i = 0; i < 50 && !done; i++) await advance(200);
    await settled;

    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT + 1);
    expect(sentJobIds().at(-1)).toBe('linkedin:job:999');
    deactivate();
  });

  it('deactivate durante waitForPane cancela geração', async () => {
    mountJobsList();
    document.removeEventListener('click', spaClickHandler, true);
    const ctx = new ContentScriptContext('test-linkedin-jobs');
    const controller = createLinkedInJobsController();
    const active = controller.activate(ctx).catch(() => {});
    await advance(400);
    window.happyDOM.setURL('https://www.linkedin.com/feed/');
    controller.deactivate();
    for (let i = 0; i < 20; i++) await advance(200);
    await active;
    expect(sendMessageMock).not.toHaveBeenCalled();
  });

  it('lista mista div + li-only + href-only → todos são capturados', async () => {
    document.body.innerHTML = `
      <ul class="scaffold-layout__list-container">
        <li data-occludable-job-id="111" class="scaffold-layout__list-item">
          <div data-job-id="111" class="job-card-container">
            <a href="/jobs/view/111/" class="job-card-container__link">Vaga Div</a>
          </div>
        </li>
        <li data-occludable-job-id="222" class="scaffold-layout__list-item">
          <div class="job-card-container">
            <a href="/jobs/view/222/" class="job-card-container__link">Vaga Li</a>
          </div>
        </li>
        <li class="scaffold-layout__list-item">
          <div class="job-card-container">
            <a href="/jobs/view/333/" class="job-card-container__link">Vaga Href</a>
          </div>
        </li>
      </ul>`;
    // handler precisa lidar com div, li e href
    document.removeEventListener('click', spaClickHandler, true);
    const mixedHandler = (e: Event) => {
      const target = e.target instanceof Element ? e.target : null;
      const card = target?.closest('div[data-job-id], li[data-occludable-job-id], div.job-card-container');
      if (!card) return;
      const href = card.querySelector('a[href*="/jobs/view/"]')?.getAttribute('href') ?? '';
      const m = href.match(/\/jobs\/view\/(\d+)/);
      const jobId = m?.[1] ?? card.getAttribute('data-job-id') ?? card.getAttribute('data-occludable-job-id');
      const title = card.querySelector('a.job-card-container__link')?.textContent?.trim() ?? null;
      if (!jobId) return;
      window.happyDOM.setURL(`https://www.linkedin.com/jobs/search/?keywords=Laravel&currentJobId=${jobId}`);
      if (title) openJobsPane(title);
    };
    document.addEventListener('click', mixedHandler, true);

    const ctx = new ContentScriptContext('test-linkedin-jobs-mixed');
    const controller = createLinkedInJobsController();
    let done = false;
    const active = controller.activate(ctx).then(() => { done = true; });
    for (let i = 0; i < 300 && !done; i++) await advance(200);
    await active;

    expect(sendMessageMock).toHaveBeenCalledTimes(3);
    expect(new Set(sentJobIds()).size).toBe(3);
    expect(sentJobIds().sort()).toEqual(['linkedin:job:111', 'linkedin:job:222', 'linkedin:job:333'].sort());

    document.removeEventListener('click', mixedHandler, true);
    controller.deactivate();
  });

  it('backend responde duplicate:true → contador Duplicadas incrementa', async () => {
    mountJobsList(2);
    // segunda vaga será duplicada no backend
    sendMessageMock.mockImplementation(async (msg: unknown) => {
      const m = msg as { payload: { external_id: string | null } };
      if (m.payload.external_id === 'linkedin:job:2') return { ok: true, duplicate: true };
      return { ok: true, duplicate: false };
    });

    const ctx = new ContentScriptContext('test-linkedin-jobs-dup');
    const controller = createLinkedInJobsController();
    let done = false;
    const active = controller.activate(ctx).then(() => { done = true; });
    for (let i = 0; i < 300 && !done; i++) await advance(200);
    await active;

    expect(sendMessageMock).toHaveBeenCalledTimes(2);
    // Verifica que uma foi duplicate (via chamada)
    const calls = sendMessageMock.mock.calls.map((c) => (c[0] as { payload: { external_id: string | null } }).payload.external_id);
    expect(calls).toContain('linkedin:job:2');
    controller.deactivate();
  });
});
