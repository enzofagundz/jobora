<script lang="ts" setup>
import { computed, nextTick, ref, onMounted, onUnmounted } from 'vue';
import {
  apiBaseUrl,
  apiToken,
  captureEnabled,
  lastApiStatus,
  type LastApiStatus,
} from '@/utils/storage';
import { hasConfiguredToken } from '@/utils/capture-control';
import { sanitizeApiBaseUrl, validateCaptureToken } from '@/api/captures';
import type {
  CaptureErrorKind,
  CaptureQueueItemStatus,
  CaptureQueueStatus,
  QueueBlockedReason,
} from '@/types/capture';

const url = ref('');
const token = ref('');
const tokenSet = ref(false);
const captureEnabledValue = ref(false);
const savedStatus = ref<LastApiStatus | null>(null);
const message = ref('');
const queueStatus = ref<CaptureQueueStatus | null>(null);
const queueLoading = ref(true);
const queueError = ref(false);
const queueAuthRequired = ref(false);
const validatedToken = ref('');
const actionKey = ref<string | null>(null);
const actionError = ref('');
const actionInfo = ref('');
const pendingDiscardKey = ref<string | null>(null);

let queueRequest = 0;

let unwatchUrl: (() => void) | undefined;
let unwatchToken: (() => void) | undefined;
let unwatchCapture: (() => void) | undefined;
let unwatchStatus: (() => void) | undefined;

onMounted(async () => {
  url.value = await apiBaseUrl.getValue();
  token.value = await apiToken.getValue();
  tokenSet.value = hasConfiguredToken(token.value);
  captureEnabledValue.value = await captureEnabled.getValue();
  savedStatus.value = await lastApiStatus.getValue();
  unwatchUrl = apiBaseUrl.watch((value) => {
    url.value = value;
  });
  unwatchToken = apiToken.watch((value) => {
    token.value = value;
    syncTokenState(value);
  });
  unwatchCapture = captureEnabled.watch((value) => {
    captureEnabledValue.value = value;
  });
  unwatchStatus = lastApiStatus.watch(() => {
    void refreshQueue();
  });
  void refreshQueue();
});

interface QueueStatusResponse {
  ok: true;
  status: CaptureQueueStatus;
}

interface QueueErrorResponse {
  ok: false;
  error: CaptureErrorKind;
}

function isQueueStatusResponse(value: unknown): value is QueueStatusResponse {
  if (typeof value !== 'object' || value === null) return false;
  const response = value as { ok?: unknown; status?: unknown };
  if (response.ok !== true || typeof response.status !== 'object' || response.status === null) {
    return false;
  }
  const status = response.status as Partial<CaptureQueueStatus>;
  return (
    typeof status.pending === 'number' &&
    typeof status.blocked === 'number' &&
    typeof status.bytes === 'number' &&
    typeof status.max_bytes === 'number' &&
    Array.isArray(status.items)
  );
}

function isQueueErrorResponse(value: unknown): value is QueueErrorResponse {
  if (typeof value !== 'object' || value === null) return false;
  const response = value as { ok?: unknown; error?: unknown };
  return response.ok === false && typeof response.error === 'string';
}

async function refreshQueue(): Promise<void> {
  const request = ++queueRequest;
  queueLoading.value = true;
  queueError.value = false;
  queueAuthRequired.value = false;
  try {
    const response: unknown = await browser.runtime.sendMessage({
      type: 'capture:queue-status',
    });
    if (request !== queueRequest) return;
    if (isQueueStatusResponse(response)) {
      queueStatus.value = response.status;
      return;
    }
    if (isQueueErrorResponse(response) && response.error === 'auth') {
      queueStatus.value = null;
      queueAuthRequired.value = true;
      return;
    }
    queueError.value = true;
  } catch {
    if (request === queueRequest) queueError.value = true;
  } finally {
    if (request === queueRequest) queueLoading.value = false;
  }
}

const pendingItems = computed<CaptureQueueItemStatus[]>(
  () => queueStatus.value?.items.filter((item) => item.state === 'pending') ?? [],
);

const blockedItems = computed<CaptureQueueItemStatus[]>(
  () => queueStatus.value?.items.filter((item) => item.state === 'blocked') ?? [],
);

const pendingLabel = computed(() => {
  const count = queueStatus.value?.pending ?? 0;
  return count === 1 ? '1 captura aguardando envio.' : `${count} capturas aguardando envio.`;
});

const pendingReason = computed(() => {
  if (
    pendingItems.value.some(
      (item) => item.last_error === 'network' || item.last_error === 'timeout',
    )
  ) {
    return 'Sem conexão com a API. A nova tentativa é automática.';
  }
  if (pendingItems.value.some((item) => item.last_status === 429)) {
    return 'Limite de requisições da API. A nova tentativa é automática.';
  }
  if (
    pendingItems.value.some(
      (item) => (item.last_status ?? 0) >= 500 && (item.last_status ?? 0) <= 599,
    )
  ) {
    return 'API indisponível no momento. A nova tentativa é automática.';
  }
  return '';
});

const nextAttemptLabel = computed(() => {
  const nextAttemptAt = queueStatus.value?.next_attempt_at;
  if (!nextAttemptAt) return '';
  return `Nova tentativa ${formatRelative(nextAttemptAt)}.`;
});

const tokenValidated = computed(() => {
  const trimmed = token.value.trim();
  return trimmed !== '' && trimmed === validatedToken.value;
});

const canResumeAuth = computed(
  () => tokenValidated.value && tokenSet.value && captureEnabledValue.value,
);

const resumeGuidance = computed(() => {
  if (!tokenSet.value) return 'Conecte uma conta para retomar.';
  if (!captureEnabledValue.value) return 'Ative a captura para retomar.';
  if (!tokenValidated.value) return 'Salve um token válido para retomar.';
  return '';
});

const authGuidance = computed(() => {
  if (!tokenSet.value) return 'Conecte uma conta: salve um token para ver as capturas guardadas.';
  if (!captureEnabledValue.value) return 'Ative a captura para ver e enviar as capturas guardadas.';
  return 'Credencial não aceita. Salve um token válido para conectar.';
});

const reasonLabels: Record<QueueBlockedReason, string> = {
  auth: 'token recusado (401)',
  invalid: 'dados recusados (422)',
  conflict: 'conflito de chave (409)',
  too_large: 'acima do limite',
  retry_exhausted: 'falhas repetidas',
};

function reasonLabel(item: CaptureQueueItemStatus): string {
  return item.blocked_reason ? reasonLabels[item.blocked_reason] : 'falha não identificada';
}

function reasonHint(item: CaptureQueueItemStatus): string {
  if (item.blocked_reason === 'auth') {
    return canResumeAuth.value ? 'Use Retomar para enviar de novo.' : '';
  }
  if (item.blocked_reason === 'invalid') {
    return 'Descarte e capture de novo depois de corrigir os dados. Este envio não será repetido.';
  }
  if (item.blocked_reason === 'conflict') {
    return 'O mesmo envio já existe com outro conteúdo.';
  }
  if (item.blocked_reason === 'too_large') {
    return `Acima do limite de ${formatBytes(queueStatus.value?.envelope_max_bytes ?? 0)} por captura.`;
  }
  if (item.blocked_reason === 'retry_exhausted') {
    return 'Falhas repetidas de rede ou servidor. A captura continua guardada.';
  }
  return '';
}

function actionErrorMessage(
  error: CaptureErrorKind,
  type: 'capture:queue-resume' | 'capture:queue-discard',
): string {
  if (error === 'auth') return 'Credencial recusada. Salve um token válido para conectar.';
  if (error === 'credential_changed') {
    return 'Esta captura pertence a outra conta. Desconecte e conecte a conta original.';
  }
  if (error === 'not_found') return 'A captura não está mais na fila.';
  if (error === 'invalid') {
    return type === 'capture:queue-resume'
      ? 'Esta captura não pode ser retomada. Descarte e capture de novo.'
      : 'Esta captura não pode ser descartada.';
  }
  return 'Não foi possível falar com o serviço. Tente de novo.';
}

async function runQueueAction(
  message: { type: 'capture:queue-resume' | 'capture:queue-discard'; idempotency_key: string },
  successMessage: string,
): Promise<void> {
  actionKey.value = message.idempotency_key;
  actionError.value = '';
  actionInfo.value = '';
  try {
    const response: unknown = await browser.runtime.sendMessage(message);
    if (isQueueStatusResponse(response)) {
      queueStatus.value = response.status;
      queueAuthRequired.value = false;
      actionInfo.value = successMessage;
      return;
    }
    if (isQueueErrorResponse(response)) {
      actionError.value = actionErrorMessage(response.error, message.type);
      if (response.error === 'auth') validatedToken.value = '';
      if (response.error === 'not_found') void refreshQueue();
      return;
    }
    actionError.value = 'Não foi possível concluir a ação. Tente de novo.';
  } catch {
    actionError.value = 'Não foi possível falar com o serviço. Tente de novo.';
  } finally {
    actionKey.value = null;
  }
}

async function resumeCapture(item: CaptureQueueItemStatus): Promise<void> {
  await runQueueAction(
    { type: 'capture:queue-resume', idempotency_key: item.idempotency_key },
    'Captura retomada.',
  );
}

async function askDiscard(item: CaptureQueueItemStatus, index: number): Promise<void> {
  pendingDiscardKey.value = item.idempotency_key;
  await nextTick();
  document.querySelector<HTMLButtonElement>(`[data-confirm-index="${index}"]`)?.focus();
}

function cancelDiscard(index: number): void {
  pendingDiscardKey.value = null;
  void nextTick().then(() => {
    document.querySelector<HTMLButtonElement>(`[data-discard-index="${index}"]`)?.focus();
  });
}

async function discardCapture(item: CaptureQueueItemStatus): Promise<void> {
  await runQueueAction(
    { type: 'capture:queue-discard', idempotency_key: item.idempotency_key },
    'Captura descartada.',
  );
  pendingDiscardKey.value = null;
}

const quotaPercent = computed(() => {
  const status = queueStatus.value;
  if (!status || status.max_bytes <= 0) return 0;
  return Math.min(100, Math.round((status.bytes / status.max_bytes) * 100));
});

const quotaLabel = computed(() => {
  const status = queueStatus.value;
  if (!status) return '';
  return `${formatBytes(status.bytes)} de ${formatBytes(status.max_bytes)}`;
});

const quotaNearLimit = computed(
  () => quotaPercent.value >= 90 && (queueStatus.value?.bytes ?? 0) > 0,
);

function formatBytes(bytes: number): string {
  if (bytes > 0 && bytes < 1024 * 1024) {
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }
  const megabytes = bytes / (1024 * 1024);
  const fractionDigits = megabytes > 0 && megabytes < 10 ? 1 : 0;
  const value = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(megabytes);
  return `${value} MB`;
}

function formatRelative(timestamp: number): string {
  const remaining = timestamp - Date.now();
  if (remaining <= 0) return 'agora';
  if (remaining < 60_000) return `em ${Math.max(1, Math.ceil(remaining / 1_000))} s`;
  if (remaining < 3_600_000) return `em ${Math.ceil(remaining / 60_000)} min`;
  return `em ${Math.ceil(remaining / 3_600_000)} h`;
}

/** Recalcula tokenSet; token vazio força o toggle off e persiste false. */
async function syncTokenState(value: string): Promise<void> {
  tokenSet.value = hasConfiguredToken(value);
  if (!tokenSet.value && captureEnabledValue.value) {
    captureEnabledValue.value = false;
    await captureEnabled.setValue(false);
  }
}

onUnmounted(() => {
  unwatchUrl?.();
  unwatchToken?.();
  unwatchCapture?.();
  unwatchStatus?.();
});

async function onCaptureToggle(): Promise<void> {
  await captureEnabled.setValue(captureEnabledValue.value);
  void refreshQueue();
}

async function save(): Promise<void> {
  const clean = sanitizeApiBaseUrl(url.value);
  if (!clean) {
    message.value =
      'URL inválida. Aceita apenas http://localhost:8000, http://127.0.0.1:8000, https://vagas.test, https://jobora.test ou https://jobora-v2.vagas.test, sem barra final, query ou fragmento.';
    return;
  }
  url.value = clean;
  await apiBaseUrl.setValue(clean);
  const cleanToken = token.value.trim();
  await apiToken.setValue(cleanToken);
  // Salvar nunca ativa; token vazio salvo força o toggle off.
  if (!hasConfiguredToken(cleanToken) && captureEnabledValue.value) {
    captureEnabledValue.value = false;
    await captureEnabled.setValue(false);
  }

  if (!hasConfiguredToken(cleanToken)) {
    validatedToken.value = '';
    message.value = 'Configuração salva.';
    void refreshQueue();
    return;
  }

  const result = await validateCaptureToken();
  validatedToken.value = result.ok ? cleanToken : '';
  void refreshQueue();
  if (result.ok) {
    message.value = 'Conectado — credencial válida.';
  } else if (result.error === 'auth') {
    message.value = 'Token inválido.';
  } else if (result.error === 'timeout') {
    message.value = 'Tempo esgotado ao validar.';
  } else if (result.error === 'network') {
    message.value = 'Falha de rede ao validar.';
  } else {
    message.value = 'Falha ao validar.';
  }
}

/** Equivalente ao "Limpar token" do menu do userscript; mantém a URL salva. */
async function disconnect(): Promise<void> {
  captureEnabledValue.value = false;
  await captureEnabled.setValue(false);
  token.value = '';
  await apiToken.removeValue();
  await lastApiStatus.setValue(null);
  validatedToken.value = '';
  message.value = 'Token removido.';
  void refreshQueue();
}
</script>

<template>
  <main class="popup">
    <h1>Jobora Job Capture</h1>

    <label for="api-url">URL da API</label>
    <input id="api-url" v-model="url" type="url" placeholder="https://vagas.test" />

    <label for="api-token">Token (credencial da conta)</label>
    <input
      id="api-token"
      v-model="token"
      type="password"
      placeholder="cole o token aqui"
      autocomplete="off"
    />

    <div class="actions">
      <button type="button" @click="save">Salvar</button>
      <button type="button" @click="disconnect">Desconectar</button>
    </div>

    <div class="capture-toggle">
      <input
        id="capture-enabled"
        v-model="captureEnabledValue"
        type="checkbox"
        :disabled="!tokenSet"
        @change="onCaptureToggle"
      />
      <label for="capture-enabled">Ativar captura</label>
    </div>
    <p v-if="!tokenSet" class="message">Configure e salve um token antes de ativar a captura.</p>

    <p v-if="message" class="message">{{ message }}</p>

    <dl>
      <dt>Conexão</dt>
      <dd>{{ tokenSet ? 'token configurado' : 'não configurado' }}</dd>

      <dt>API</dt>
      <dd>{{ url || '—' }}</dd>

      <template v-if="savedStatus">
        <dt>Último resultado</dt>
        <dd>
          {{ savedStatus.ok ? (savedStatus.duplicate ? 'duplicada' : 'enviada') : savedStatus.error }}
          <span v-if="savedStatus.status">({{ savedStatus.status }})</span>
        </dd>
      </template>
    </dl>

    <section class="queue" aria-labelledby="queue-title" :aria-busy="queueLoading">
      <div class="queue-head">
        <h2 id="queue-title">Fila de capturas</h2>
        <button
          type="button"
          class="queue-refresh"
          :disabled="queueLoading"
          @click="refreshQueue"
        >
          {{ queueLoading && queueStatus ? 'Atualizando…' : 'Atualizar' }}
        </button>
      </div>

      <p v-if="actionInfo" class="queue-success" role="status">{{ actionInfo }}</p>
      <p v-if="actionError" class="queue-error" role="alert">{{ actionError }}</p>

      <p v-if="queueLoading && !queueStatus" class="queue-muted">Verificando fila…</p>
      <p v-else-if="queueError" class="queue-error">
        Não foi possível ler a fila. Tente Atualizar.
      </p>
      <p v-else-if="queueAuthRequired" class="queue-auth">{{ authGuidance }}</p>

      <template v-else-if="queueStatus">
        <p v-if="queueStatus.pending === 0 && queueStatus.blocked === 0" class="queue-muted">
          Nenhuma captura pendente.
        </p>

        <template v-else>
          <p v-if="queueStatus.pending > 0" class="queue-pending">
            {{ pendingLabel }}
            <span v-if="pendingReason" class="queue-muted">{{ pendingReason }}</span>
            <span v-if="nextAttemptLabel" class="queue-muted">{{ nextAttemptLabel }}</span>
          </p>

          <template v-if="blockedItems.length > 0">
            <h3 class="queue-subtitle">Bloqueadas ({{ queueStatus.blocked }})</h3>
            <ul class="queue-blocked-list">
              <li
                v-for="(blockedItem, index) in blockedItems"
                :key="blockedItem.idempotency_key"
                class="queue-blocked-item"
              >
                <div class="queue-blocked-head">
                  <span class="queue-blocked-label">
                    Captura {{ index + 1 }} · {{ reasonLabel(blockedItem) }}
                  </span>
                  <div class="queue-actions">
                    <button
                      v-if="blockedItem.blocked_reason === 'auth' && canResumeAuth"
                      type="button"
                      :disabled="actionKey !== null"
                      @click="resumeCapture(blockedItem)"
                    >
                      {{ actionKey === blockedItem.idempotency_key ? 'Retomando…' : 'Retomar' }}
                    </button>
                    <button
                      type="button"
                      class="queue-action-discard"
                      :data-discard-index="index"
                      :aria-expanded="pendingDiscardKey === blockedItem.idempotency_key"
                      :disabled="actionKey !== null"
                      @click="askDiscard(blockedItem, index)"
                    >
                      Descartar
                    </button>
                  </div>
                </div>

                <p class="queue-reason">{{ reasonHint(blockedItem) }}</p>
                <p
                  v-if="blockedItem.blocked_reason === 'auth' && !canResumeAuth"
                  class="queue-hint"
                >
                  {{ resumeGuidance }}
                </p>

                <div
                  v-if="pendingDiscardKey === blockedItem.idempotency_key"
                  class="queue-confirm"
                  role="group"
                  aria-label="Confirmar descarte"
                >
                  <span>Descartar a captura {{ index + 1 }}? Não dá para desfazer.</span>
                  <button
                    type="button"
                    class="queue-confirm-discard"
                    :data-confirm-index="index"
                    :disabled="actionKey !== null"
                    @click="discardCapture(blockedItem)"
                  >
                    Confirmar descarte
                  </button>
                  <button type="button" :disabled="actionKey !== null" @click="cancelDiscard(index)">
                    Cancelar
                  </button>
                </div>
              </li>
            </ul>
          </template>
        </template>

        <p class="queue-quota">
          Ocupação: <strong>{{ quotaLabel }}</strong> da fila.
          <span v-if="quotaNearLimit" class="queue-warning">Perto do limite.</span>
        </p>
        <div
          class="queue-meter"
          role="progressbar"
          aria-label="Ocupação da fila de capturas"
          aria-valuemin="0"
          :aria-valuemax="queueStatus.max_bytes"
          :aria-valuenow="queueStatus.bytes"
          :aria-valuetext="quotaLabel"
        >
          <span
            class="queue-meter-fill"
            :class="{ 'queue-meter-tight': quotaNearLimit }"
            :style="{ width: `${quotaPercent}%` }"
          ></span>
        </div>
      </template>
    </section>

    <p class="hint">Abra uma busca no LinkedIn ou no Indeed para capturar publicações.</p>
  </main>
</template>

<style>
.popup {
  width: 300px;
  padding: 12px 16px;
  font: 13px/1.5 system-ui, sans-serif;
  color: #e2e8f0;
  background: #0f172a;
}
h1 {
  font-size: 15px;
  margin: 0 0 10px;
}
label {
  display: block;
  margin-top: 8px;
  font-weight: 600;
}
input {
  width: 100%;
  box-sizing: border-box;
  margin-top: 2px;
  padding: 6px 8px;
  border-radius: 6px;
  border: 1px solid #334155;
  background: #1e293b;
  color: #e2e8f0;
}
.actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
}
button {
  padding: 6px 12px;
  border-radius: 6px;
  border: none;
  cursor: pointer;
  font-weight: 600;
  background: #2563eb;
  color: white;
}
.actions button:last-child {
  background: #475569;
}
.message {
  margin: 8px 0 0;
  color: #fbbf24;
}
dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 2px 10px;
  margin: 12px 0 0;
}
dt {
  font-weight: 600;
}
dd {
  margin: 0;
  word-break: break-all;
}
.queue {
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid #1e293b;
}
.queue-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.queue h2 {
  margin: 0;
  font-size: 13px;
}
.queue-refresh {
  padding: 3px 8px;
  border: 1px solid #334155;
  background: #1e293b;
  font-size: 12px;
}
.queue-refresh:disabled {
  opacity: 0.6;
  cursor: default;
}
.queue p {
  margin: 6px 0 0;
}
.queue-muted {
  color: #94a3b8;
}
.queue-error,
.queue-warning {
  color: #fbbf24;
}
.queue-auth {
  color: #94a3b8;
}
.queue-success {
  color: #86efac;
}
.queue-subtitle {
  margin: 8px 0 0;
  color: #cbd5e1;
  font-size: 12px;
}
.queue-blocked-list {
  max-height: 240px;
  margin: 4px 0 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}
.queue-blocked-list::-webkit-scrollbar {
  width: 6px;
}
.queue-blocked-list::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: #334155;
}
.queue-blocked-item {
  padding: 6px 0;
  border-top: 1px solid #1e293b;
}
.queue-blocked-item:first-child {
  padding-top: 0;
  border-top: 0;
}
.queue-blocked-head {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: start;
  gap: 6px;
}
.queue-blocked-label {
  min-width: 0;
  font-weight: 600;
  overflow-wrap: anywhere;
}
.queue-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 4px;
}
.queue-actions button,
.queue-confirm button {
  padding: 3px 8px;
  font-size: 12px;
}
.queue-action-discard {
  border: 1px solid #475569;
  background: #334155;
}
.queue-reason {
  color: #cbd5e1;
}
.queue-hint {
  color: #94a3b8;
}
.queue-confirm {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  padding: 6px 8px;
  border-radius: 6px;
  background: #1e293b;
}
.queue-confirm span {
  flex-basis: 100%;
}
.queue-confirm-discard {
  background: #b91c1c;
}
.queue-confirm button:last-child {
  background: #475569;
}
.queue button:disabled {
  opacity: 0.6;
  cursor: default;
}
.queue-quota {
  color: #cbd5e1;
}
.queue-meter {
  height: 4px;
  margin-top: 5px;
  overflow: hidden;
  border-radius: 999px;
  background: #1e293b;
}
.queue-meter-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: #2563eb;
}
.queue-meter-tight {
  background: #f59e0b;
}
button:focus-visible,
input:focus-visible {
  outline: 2px solid #60a5fa;
  outline-offset: 2px;
}
.hint {
  margin-top: 12px;
  color: #94a3b8;
}
.capture-toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
}
.capture-toggle input[type='checkbox'] {
  width: auto;
  margin: 0;
}
.capture-toggle input:disabled + label {
  color: #64748b;
}
</style>
