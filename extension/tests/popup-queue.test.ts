// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, type App as VueApp } from 'vue';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import PopupApp from '@/entrypoints/popup/App.vue';
import type {
  CaptureErrorKind,
  CaptureQueueItemStatus,
  CaptureQueueStatus,
} from '@/types/capture';
import {
  apiBaseUrl,
  apiToken,
  captureEnabled,
  lastApiStatus,
} from '@/utils/storage';

const TOKEN = 'segredo-que-nao-pode-aparecer';

function item(overrides: Partial<CaptureQueueItemStatus>): CaptureQueueItemStatus {
  return {
    idempotency_key: 'cap-key-1',
    execution_id: 'exec-1',
    state: 'pending',
    attempts: 0,
    next_attempt_at: null,
    bytes: 1_024,
    last_error: undefined,
    last_status: undefined,
    blocked_reason: undefined,
    ...overrides,
  };
}

function status(items: CaptureQueueItemStatus[]): CaptureQueueStatus {
  return {
    pending: items.filter((entry) => entry.state === 'pending').length,
    blocked: items.filter((entry) => entry.state === 'blocked').length,
    bytes: items.reduce((total, entry) => total + entry.bytes, 0),
    max_bytes: 36 * 1024 * 1024,
    envelope_max_bytes: 12 * 1024 * 1024,
    next_attempt_at:
      items.find((entry) => entry.state === 'pending')?.next_attempt_at ?? null,
    items,
  };
}

function queueResponse(value: CaptureQueueStatus) {
  return { ok: true as const, status: value };
}

function queueFailure(error: CaptureErrorKind) {
  return { ok: false as const, error };
}

function buttonByText(root: ParentNode, text: string): HTMLButtonElement | undefined {
  return Array.from(root.querySelectorAll('button')).find(
    (button) => button.textContent?.trim() === text,
  );
}

function blockedRow(root: ParentNode, text: string): HTMLElement | undefined {
  return Array.from(root.querySelectorAll<HTMLElement>('.queue-blocked-item')).find((row) =>
    row.textContent?.includes(text),
  );
}

describe('popup — fila de capturas', () => {
  let container: HTMLDivElement;
  let app: VueApp | undefined;
  let sendMessage: ReturnType<typeof vi.spyOn>;

  beforeEach(async () => {
    fakeBrowser.reset();
    container = document.createElement('div');
    document.body.appendChild(container);
    await apiBaseUrl.setValue('https://vagas.test');
    await apiToken.setValue(TOKEN);
    await captureEnabled.setValue(true);
    await lastApiStatus.setValue(null);
    sendMessage = vi.spyOn(fakeBrowser.runtime, 'sendMessage');
  });

  function sentMessages(): Array<{ type?: string; idempotency_key?: string }> {
    return (sendMessage.mock.calls as unknown[][]).map(
      (call) => call[0] as { type?: string; idempotency_key?: string },
    );
  }

  afterEach(() => {
    app?.unmount();
    app = undefined;
    container.remove();
    fakeBrowser.reset();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  async function mountWithHandler(
    handler: (message: unknown) => Promise<unknown>,
  ): Promise<void> {
    sendMessage.mockImplementation(handler);
    app = createApp(PopupApp);
    app.mount(container);
    await vi.waitFor(() => {
      expect(container.textContent).toContain('Fila de capturas');
      expect(container.textContent).not.toContain('Verificando fila…');
    });
  }

  async function mountReply(reply: unknown): Promise<void> {
    await mountWithHandler(async () => reply);
  }

  async function mountWith(value: CaptureQueueStatus): Promise<HTMLDivElement> {
    await mountReply(queueResponse(value));
    return container;
  }

  it('mostra bloqueios por 401, 422, conflito e limite sem expor segredo ou ids', async () => {
    await mountWith(
      status([
        item({
          idempotency_key: 'cap-401',
          state: 'blocked',
          attempts: 1,
          blocked_reason: 'auth',
          last_error: 'auth',
          last_status: 401,
        }),
        item({
          idempotency_key: 'cap-422',
          state: 'blocked',
          attempts: 1,
          blocked_reason: 'invalid',
          last_error: 'invalid',
          last_status: 422,
          bytes: 2_048,
        }),
        item({
          idempotency_key: 'cap-409',
          state: 'blocked',
          attempts: 1,
          blocked_reason: 'conflict',
          last_error: 'conflict',
          last_status: 409,
          bytes: 4_096,
        }),
        item({
          idempotency_key: 'cap-large',
          state: 'blocked',
          attempts: 1,
          blocked_reason: 'too_large',
          last_error: 'too_large',
          last_status: 413,
          bytes: 12 * 1024 * 1024,
        }),
      ]),
    );

    const text = container.textContent ?? '';
    expect(text).toContain('401');
    expect(text).toContain('422');
    expect(text).toContain('conflito');
    expect(text).toContain('limite');
    expect(text).toContain('12 MB');
    expect(text).toContain('36 MB');
    expect(text).not.toContain(TOKEN);
    expect(text).not.toContain('cap-401');
    expect(text).not.toContain('exec-1');
    expect(text).not.toContain('<main');
    expect(container.innerHTML).not.toContain(TOKEN);
    expect(container.innerHTML).not.toContain('cap-401');
    expect(container.innerHTML).not.toContain('exec-1');
  });

  it('explica pendência de rede e próxima tentativa automática', async () => {
    await mountWith(
      status([
        item({
          idempotency_key: 'cap-network',
          attempts: 1,
          next_attempt_at: Date.now() + 30_000,
          last_error: 'network',
        }),
      ]),
    );

    const text = container.textContent ?? '';
    expect(text).toContain('1 captura aguardando envio');
    expect(text).toContain('Sem conexão com a API');
    expect(text).toContain('Nova tentativa');
    expect(text).not.toContain('cap-network');
  });

  it('ação existente (Ativar captura) atualiza o status da fila', async () => {
    await mountWith(
      status([
        item({
          idempotency_key: 'cap-network',
          attempts: 1,
          next_attempt_at: Date.now() + 30_000,
          last_error: 'network',
        }),
      ]),
    );

    const toggle = container.querySelector<HTMLInputElement>('#capture-enabled');
    expect(toggle).toBeTruthy();
    toggle!.checked = false;
    toggle!.dispatchEvent(new Event('change'));

    await vi.waitFor(() => {
      expect(sendMessage).toHaveBeenCalledTimes(2);
    });
    expect(await captureEnabled.getValue()).toBe(false);
  });

  it('Atualizar relê o status da fila', async () => {
    await mountWith(
      status([
        item({
          idempotency_key: 'cap-network',
          attempts: 1,
          next_attempt_at: Date.now() + 30_000,
          last_error: 'network',
        }),
      ]),
    );

    sendMessage.mockResolvedValue(
      queueResponse(
        status([
          item({
            idempotency_key: 'cap-422',
            state: 'blocked',
            attempts: 1,
            blocked_reason: 'invalid',
            last_error: 'invalid',
            last_status: 422,
          }),
        ]),
      ),
    );

    const refresh = Array.from(container.querySelectorAll('button')).find((button) =>
      button.textContent?.includes('Atualizar'),
    );
    expect(refresh).toBeTruthy();
    refresh?.click();

    await vi.waitFor(() => {
      expect(container.textContent).toContain('422');
    });
    expect(container.textContent).not.toContain('aguardando envio');
    expect(sendMessage).toHaveBeenCalledTimes(2);
  });

  it('sem credencial orienta conectar, sem erro técnico genérico', async () => {
    await apiToken.removeValue();
    await captureEnabled.setValue(false);

    await mountReply(queueFailure('auth'));

    await vi.waitFor(() => {
      expect(container.textContent).toContain('Conecte uma conta');
    });
    expect(container.textContent).not.toContain('Não foi possível ler a fila');
  });

  it('Retomar aparece após token válido, só em auth, e usa a chave interna', async () => {
    const authItem = item({
      idempotency_key: 'cap-401',
      state: 'blocked',
      attempts: 1,
      blocked_reason: 'auth',
      last_error: 'auth',
      last_status: 401,
    });
    const invalidItem = item({
      idempotency_key: 'cap-422',
      state: 'blocked',
      attempts: 1,
      blocked_reason: 'invalid',
      last_error: 'invalid',
      last_status: 422,
    });

    await mountWithHandler(async (message) => {
      if ((message as { type?: string }).type === 'capture:queue-resume') {
        return queueResponse(status([invalidItem]));
      }
      return queueResponse(status([authItem, invalidItem]));
    });

    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        new Response(JSON.stringify({ valid: true, owner_user_id: 1 }), { status: 200 }),
      ),
    );

    expect(buttonByText(container, 'Retomar')).toBeUndefined();

    buttonByText(container, 'Salvar')!.click();
    await vi.waitFor(() => {
      expect(container.textContent).toContain('Conectado');
      expect(buttonByText(container, 'Retomar')).toBeTruthy();
    });

    const invalidRow = blockedRow(container, '422');
    expect(invalidRow).toBeTruthy();
    expect(buttonByText(invalidRow!, 'Retomar')).toBeUndefined();
    expect(invalidRow!.textContent).toContain('Descarte e capture de novo');

    buttonByText(container, 'Retomar')!.click();
    await vi.waitFor(() => {
      expect(container.textContent).toContain('Captura retomada');
    });

    expect(
      sentMessages().some(
        (message) =>
          message.type === 'capture:queue-resume' && message.idempotency_key === 'cap-401',
      ),
    ).toBe(true);
  });

  it('Descartar pede confirmação, cancela sem apagar e confirma com a chave interna', async () => {
    const blockedItem = item({
      idempotency_key: 'cap-422',
      state: 'blocked',
      attempts: 1,
      blocked_reason: 'invalid',
      last_error: 'invalid',
      last_status: 422,
    });

    await mountWithHandler(async (message) => {
      if ((message as { type?: string }).type === 'capture:queue-discard') {
        return queueResponse(status([]));
      }
      return queueResponse(status([blockedItem]));
    });

    const row = blockedRow(container, '422');
    expect(row).toBeTruthy();

    buttonByText(row!, 'Descartar')!.click();
    await vi.waitFor(() => {
      expect(container.textContent).toContain('Não dá para desfazer');
    });
    expect(container.textContent).toContain('Confirmar descarte');
    expect(sentMessages().some((message) => message.type === 'capture:queue-discard')).toBe(false);

    buttonByText(container, 'Cancelar')!.click();
    await vi.waitFor(() => {
      expect(container.textContent).not.toContain('Não dá para desfazer');
    });
    expect(document.activeElement).toBe(buttonByText(row!, 'Descartar'));
    expect(sentMessages().some((message) => message.type === 'capture:queue-discard')).toBe(false);

    buttonByText(row!, 'Descartar')!.click();
    await vi.waitFor(() => {
      expect(container.textContent).toContain('Confirmar descarte');
    });
    buttonByText(container, 'Confirmar descarte')!.click();

    await vi.waitFor(() => {
      expect(container.textContent).toContain('Captura descartada');
      expect(container.textContent).not.toContain('422');
    });
    expect(
      sentMessages().some(
        (message) =>
          message.type === 'capture:queue-discard' && message.idempotency_key === 'cap-422',
      ),
    ).toBe(true);
  });

  it('erro auth ao descartar orienta conectar e mantém a captura', async () => {
    const blockedItem = item({
      idempotency_key: 'cap-422',
      state: 'blocked',
      attempts: 1,
      blocked_reason: 'invalid',
      last_error: 'invalid',
      last_status: 422,
    });

    await mountWithHandler(async (message) => {
      if ((message as { type?: string }).type === 'capture:queue-discard') {
        return queueFailure('auth');
      }
      return queueResponse(status([blockedItem]));
    });

    const row = blockedRow(container, '422');
    buttonByText(row!, 'Descartar')!.click();
    await vi.waitFor(() => {
      expect(container.textContent).toContain('Confirmar descarte');
    });
    buttonByText(container, 'Confirmar descarte')!.click();

    await vi.waitFor(() => {
      expect(container.textContent).toContain('Credencial recusada');
    });
    expect(blockedRow(container, '422')).toBeTruthy();
  });

  it('resultado tardio em lastApiStatus recarrega a fila', async () => {
    await mountWith(
      status([
        item({
          idempotency_key: 'cap-network',
          attempts: 1,
          next_attempt_at: Date.now() + 30_000,
          last_error: 'network',
        }),
      ]),
    );

    sendMessage.mockResolvedValue(
      queueResponse(
        status([
          item({
            idempotency_key: 'cap-422',
            state: 'blocked',
            attempts: 1,
            blocked_reason: 'invalid',
            last_error: 'invalid',
            last_status: 422,
          }),
        ]),
      ),
    );

    await lastApiStatus.setValue({
      ok: false,
      error: 'invalid',
      status: 422,
      at: new Date().toISOString(),
    });

    await vi.waitFor(() => {
      expect(container.textContent).toContain('422');
    });
  });
});
