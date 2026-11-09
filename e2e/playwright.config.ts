import { defineConfig, devices } from '@playwright/test'

/* Recette sur un téléphone (390 px), comme au comptoir. */
export default defineConfig({
  testDir: './tests',
  timeout: 180_000,
  expect: { timeout: 15_000 },
  workers: 1,
  reporter: [['list'], ['html', { outputFolder: 'output/rapport', open: 'never' }]],
  outputDir: 'output/traces',
  use: {
    baseURL: 'http://127.0.0.1:4180',
    ...devices['Pixel 7'],
    locale: 'fr-HT',
    timezoneId: 'America/Port-au-Prince',
    acceptDownloads: true,
    actionTimeout: 15_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  globalSetup: './global-setup.ts',
  webServer: [
    {
      command: 'php artisan serve --host=127.0.0.1 --port=8001',
      cwd: '../apps/api',
      url: 'http://127.0.0.1:8001/api/health',
      reuseExistingServer: true,
      timeout: 60_000,
    },
    {
      command: 'node server.mjs',
      url: 'http://127.0.0.1:4180/',
      reuseExistingServer: true,
      timeout: 30_000,
    },
  ],
})
