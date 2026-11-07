// @vitest-environment happy-dom
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';

describe('retired userscripts', () => {
  it.each(['linkedin', 'indeed'])('%s does not access credentials, menus or transport', (source) => {
    const script = readFileSync(resolve(process.cwd(), '../userscripts', `${source}-job-capture.user.js`), 'utf8');
    const storage = vi.fn();
    const transport = vi.fn();
    const menu = vi.fn();
    new Function('GM_getValue', 'GM_xmlhttpRequest', 'GM_registerMenuCommand', script)(storage, transport, menu);
    expect(storage).not.toHaveBeenCalled();
    expect(transport).not.toHaveBeenCalled();
    expect(menu).not.toHaveBeenCalled();
  });
});
