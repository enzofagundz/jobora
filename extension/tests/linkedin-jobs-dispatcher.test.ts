// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import { apiBaseUrl, apiToken, captureEnabled } from '@/utils/storage';

const controllers = vi.hoisted(() => ({
  jobs: { activate: vi.fn(), deactivate: vi.fn() },
  publications: { activate: vi.fn(), deactivate: vi.fn() },
}));

vi.mock('@/sites/linkedin-jobs/controller', () => ({ createLinkedInJobsController: () => controllers.jobs }));
vi.mock('@/sites/linkedin/controller', () => ({ createLinkedInController: () => controllers.publications }));

const redirected = 'https://www.linkedin.com/jobs/search-results?skipRedirect=true&currentJobId=4486639255&keywords=Laravel&f_WT=2&f_SAL=f_SA_id_225001%3A272001&infoNotice=ai-job-search&f_TPR=r86400';
let context: ContentScriptContext | undefined;

beforeEach(async () => {
  fakeBrowser.reset();
  vi.useFakeTimers();
  document.body.innerHTML = '';
  await apiBaseUrl.setValue('https://jobora.test');
  await apiToken.setValue('controlled-routing-token');
  await captureEnabled.setValue(true);
});

afterEach(() => {
  context?.notifyInvalidated();
  context = undefined;
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('LinkedIn Jobs dispatcher search-results routing', () => {
  it.each(['cold load', 'SPA navigation'])('activates only Jobs after %s and deactivates on leaving search', async (entry) => {
    window.happyDOM.setURL(entry === 'cold load' ? redirected : 'https://www.linkedin.com/feed/');
    const { default: definition } = await import('@/entrypoints/linkedin.content');
    expect(definition.matches).toEqual(['https://www.linkedin.com/*']);
    context = new ContentScriptContext(`linkedin-jobs-route-${entry}`);
    await definition.main(context);
    if (entry === 'SPA navigation') {
      expect(controllers.jobs.activate).not.toHaveBeenCalled();
      window.happyDOM.setURL(redirected);
      await vi.advanceTimersByTimeAsync(1000);
    }
    await vi.waitFor(() => expect(controllers.jobs.activate).toHaveBeenCalledTimes(1));
    expect(controllers.publications.activate).not.toHaveBeenCalled();
    expect(window.location.href).toBe(redirected);

    window.happyDOM.setURL('https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2');
    await vi.advanceTimersByTimeAsync(1000);
    expect(controllers.jobs.activate).toHaveBeenCalledTimes(1);
    expect(controllers.jobs.deactivate).not.toHaveBeenCalled();

    window.happyDOM.setURL('https://www.linkedin.com/jobs/view/4486639255/');
    await vi.advanceTimersByTimeAsync(1000);
    await vi.waitFor(() => expect(controllers.jobs.deactivate).toHaveBeenCalledTimes(1));
    window.happyDOM.setURL(redirected);
    await vi.advanceTimersByTimeAsync(1000);
    await vi.waitFor(() => expect(controllers.jobs.activate).toHaveBeenCalledTimes(2));
  });
});
