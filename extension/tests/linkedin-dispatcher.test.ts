// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { isLinkedInRoute } from '@/sites/linkedin/extract';
import { isLinkedInJobsRoute } from '@/sites/linkedin-jobs/extract';

describe('linkedin dispatcher — rotas exclusivas e prontidão', () => {
  it('isLinkedInRoute e isLinkedInJobsRoute são mutuamente exclusivas', () => {
    const pub = new URL('https://www.linkedin.com/search/results/content/?keywords=vaga');
    const jobs = new URL('https://www.linkedin.com/jobs/search/?keywords=Laravel');
    const feed = new URL('https://www.linkedin.com/feed/');
    const view = new URL('https://www.linkedin.com/jobs/view/4461568021/');

    expect(isLinkedInRoute(pub)).toBe(true);
    expect(isLinkedInJobsRoute(pub)).toBe(false);

    expect(isLinkedInRoute(jobs)).toBe(false);
    expect(isLinkedInJobsRoute(jobs)).toBe(true);

    expect(isLinkedInRoute(feed)).toBe(false);
    expect(isLinkedInJobsRoute(feed)).toBe(false);

    expect(isLinkedInRoute(view)).toBe(false);
    expect(isLinkedInJobsRoute(view)).toBe(false);
  });

  it('entrypoints/linkedin.content/index.ts importa ambos os controllers e rotas', () => {
    const content = readFileSync('entrypoints/linkedin.content/index.ts', 'utf8');
    expect(content).toContain('isLinkedInRoute');
    expect(content).toContain('isLinkedInJobsRoute');
    expect(content).toContain('createLinkedInController');
    expect(content).toContain('createLinkedInJobsController');
    expect(content).toContain('shouldCapturePublications');
    expect(content).toContain('shouldCaptureJobs');
    expect(content).toContain('activePublications');
    expect(content).toContain('activeJobs');
  });

  it('dispatcher decide corretamente com base em rota e ready', () => {
    function shouldCapture(url: URL, ready: boolean): { pub: boolean; jobs: boolean } {
      return {
        pub: isLinkedInRoute(url) && ready,
        jobs: isLinkedInJobsRoute(url) && ready,
      };
    }

    const pubUrl = new URL('https://www.linkedin.com/search/results/content/?keywords=vaga');
    const jobsUrl = new URL('https://www.linkedin.com/jobs/search/?keywords=Laravel');

    expect(shouldCapture(pubUrl, true)).toEqual({ pub: true, jobs: false });
    expect(shouldCapture(jobsUrl, true)).toEqual({ pub: false, jobs: true });
    expect(shouldCapture(jobsUrl, false)).toEqual({ pub: false, jobs: false });
    expect(shouldCapture(pubUrl, false)).toEqual({ pub: false, jobs: false });
  });
});
