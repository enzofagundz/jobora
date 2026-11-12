import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { ALLOWED_API_ORIGINS, sanitizeApiBaseUrl } from '@/api/captures';

const config = readFileSync('wxt.config.ts', 'utf8');

describe('manifest e cliente — produção fechada', () => {
  it('não abre todas as origens nem usa wildcard de API', () => {
    expect(config).not.toContain('<all_urls>');
    expect(config).not.toContain('*://*');
    expect(config).toContain("permissions: ['storage', 'alarms']");
    for (const origin of ALLOWED_API_ORIGINS) {
      expect(config).toContain(origin + '/*');
    }
  });

  it('cliente aceita somente origins exatos declarados no manifest', () => {
    for (const origin of ALLOWED_API_ORIGINS) {
      expect(sanitizeApiBaseUrl(origin)).toBe(origin);
    }

    // Ambiente local do worktree autorizado pelo usuário.
    expect(sanitizeApiBaseUrl('https://jobora-v2.vagas.test')).toBe('https://jobora-v2.vagas.test');
    expect(config).toContain('https://jobora-v2.vagas.test/*');

    // Produção continua bloqueada até um origin exato ser aprovado e declarado.
    expect(sanitizeApiBaseUrl('https://api.jobora.example')).toBeNull();
    expect(sanitizeApiBaseUrl('https://jobora.example')).toBeNull();
    expect(sanitizeApiBaseUrl('http://localhost:8001')).toBeNull();
  });
});
