<script lang="ts" setup>
import { ref, onMounted, onUnmounted } from 'vue';
import {
  apiBaseUrl,
  apiToken,
  captureEnabled,
  lastApiStatus,
  type LastApiStatus,
} from '@/utils/storage';
import { hasConfiguredToken } from '@/utils/capture-control';
import { sanitizeApiBaseUrl, validateCaptureToken } from '@/api/captures';

const url = ref('');
const token = ref('');
const tokenSet = ref(false);
const captureEnabledValue = ref(false);
const savedStatus = ref<LastApiStatus | null>(null);
const message = ref('');

let unwatchUrl: (() => void) | undefined;
let unwatchToken: (() => void) | undefined;
let unwatchCapture: (() => void) | undefined;

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
});

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
});

async function save(): Promise<void> {
  const clean = sanitizeApiBaseUrl(url.value);
  if (!clean) {
    message.value =
      'URL inválida. Aceita apenas http://localhost:8000, http://127.0.0.1:8000, https://vagas.test ou https://jobora.test, sem barra final, query ou fragmento.';
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
    message.value = 'Configuração salva.';
    return;
  }

  const result = await validateCaptureToken();
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
  message.value = 'Token removido.';
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
        @change="captureEnabled.setValue(captureEnabledValue)"
      />
      <label for="capture-enabled">Ativar captura</label>
    </div>
    <p v-if="!tokenSet" class="message">Configure e salve um token antes de ativar a captura.</p>

    <p v-if="message" class="message">{{ message }}</p>

    <dl>
      <dt>Conexão</dt>
      <dd>{{ tokenSet ? 'token configurado' : 'não configurado' }}</dd>

      <dt>Usuário</dt>
      <dd>identidade não fornecida pela API</dd>

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
