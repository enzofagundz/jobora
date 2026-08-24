import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { WxtVitest } from 'wxt/testing/vitest-plugin';

export default defineConfig({
  define: {
    // createShadowRootUi monta `/content-scripts/${import.meta.env.ENTRYPOINT}.css`;
    // nos testes o fetch falha e vira warning inofensivo (css: "").
    'import.meta.env.ENTRYPOINT': JSON.stringify('linkedin.content'),
  },
  plugins: [WxtVitest(), vue()],
  test: {
    mockReset: true,
    restoreMocks: true,
  },
});
