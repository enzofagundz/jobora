// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import { createIndeedJobsBuilder, jobPermalink } from '@/sites/indeed/envelope';

afterEach(() => window.happyDOM.setURL('http://localhost:3000'));

describe('Indeed permalinks decodificáveis', () => {
  it.each(['de.indeed.com', 'uk.indeed.com', 'br.indeed.com', 'www.indeed.com', 'indeed.com'])(
    'gera card e detalhe com host aceito ao construir em %s',
    (host) => {
      window.happyDOM.setURL(`https://${host}/jobs?q=php`);
      const expectedHost = ['br.indeed.com', 'www.indeed.com', 'indeed.com'].includes(host)
        ? host
        : 'www.indeed.com';
      const expectedUrl = `https://${expectedHost}/viewjob?jk=job%26key`;
      expect(jobPermalink('job&key')).toBe(expectedUrl);
      const builder = createIndeedJobsBuilder('permalink-test');
      builder.addCard('job&key', document.createElement('article'));
      builder.addDetail('job&key', document.createElement('section'));
      expect(builder.blocks().map((block) => block.permalink)).toEqual([expectedUrl, expectedUrl]);
    },
  );
});
