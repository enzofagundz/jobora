import { defineConfig } from 'wxt';

export default defineConfig({
  modules: ['@wxt-dev/module-vue'],

  manifest: {
    name: 'Jobora Job Capture',
    description: 'Captura publicações de vagas no LinkedIn e Indeed e envia à API do sistema Jobora.',
    permissions: ['storage'],
    host_permissions: [
      'https://www.linkedin.com/*',
      'https://*.indeed.com/*',
      'http://localhost:8000/*',
      'http://127.0.0.1:8000/*',
      'https://vagas.test/*',
      'https://jobora.test/*',
    ],
  },

  webExt: {
    binaries: {
      chrome: '/usr/lib64/chromium-browser/chromium-browser',
    },
  },
});