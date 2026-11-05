import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.BASE_URL ?? 'http://localhost:8888',
    // The SPA's test hooks (also used by dev-env/tests/ui-e2e-checks.mjs).
    testIdAttribute: 'data-e2e',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: process.env.BASE_URL
    ? undefined
    : {
        command: 'npx wp-env start',
        url: 'http://localhost:8888',
        reuseExistingServer: true,
        timeout: 120_000,
      },
});