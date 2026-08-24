import { apiToken, captureEnabled } from '@/utils/storage';

/** Token "configurado" = não vazio após trim(); validade real continua sendo do backend (401). */
export function hasConfiguredToken(token: string): boolean {
  return token.trim().length > 0;
}

/**
 * Gate único de captura: preferência explícita do usuário + token configurado.
 * Todo ponto que decide iniciar/requisitar reutiliza este predicado.
 */
export async function isCaptureReady(): Promise<boolean> {
  const [enabled, token] = await Promise.all([
    captureEnabled.getValue(),
    apiToken.getValue(),
  ]);
  return enabled && hasConfiguredToken(token);
}
