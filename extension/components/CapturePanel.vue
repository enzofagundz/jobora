<script lang="ts" setup>
defineProps<{
  siteLabel: string;
  counters: { found: number; sent: number; duplicates: number; errors: number };
}>();

const visible = defineModel<boolean>({ default: true });
</script>

<template>
  <div v-if="visible" class="vagas-panel">
    <div class="vagas-title-row">
      <span class="vagas-dot" aria-hidden="true"></span>
      <span class="vagas-title">{{ siteLabel }}</span>
      <span class="vagas-status">ativo</span>
      <button
        type="button"
        class="vagas-close"
        title="Ocultar painel (o script continua)"
        @click="visible = false"
      >
        ✕
      </button>
    </div>
    <div class="vagas-counters">
      <template v-for="(label, key) in counterLabels" :key="key">
        <span>{{ label }}</span>
        <span class="vagas-value">{{ counters[key] }}</span>
      </template>
    </div>
  </div>
</template>

<script lang="ts">
// Rótulos exatos dos userscripts — ordem fixa.
const counterLabels = {
  found: 'Encontradas',
  sent: 'Enviadas',
  duplicates: 'Duplicadas',
  errors: 'Erros',
} as const;
</script>

<style>
.vagas-panel {
  position: absolute;
  right: 12px;
  bottom: 12px;
  z-index: 2147483647;
  background: rgba(15, 23, 42, 0.92);
  color: #e2e8f0;
  font: 12px/1.5 system-ui, sans-serif;
  padding: 10px 12px;
  border-radius: 8px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.35);
  max-width: 230px;
}
.vagas-title-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  margin-bottom: 6px;
}
.vagas-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #22c55e;
  display: inline-block;
}
.vagas-status {
  color: #4ade80;
  font-weight: 400;
}
.vagas-close {
  margin-left: auto;
  background: none;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  font-size: 12px;
}
.vagas-counters {
  display: grid;
  grid-template-columns: auto auto;
  gap: 2px 12px;
  justify-content: start;
}
.vagas-value {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
</style>
