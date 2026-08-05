const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.ESTAB_E2E_BASE_URL || 'http://127.0.0.1:18081';
const channel = process.env.ESTAB_E2E_BROWSER_CHANNEL || undefined;

module.exports = defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 120_000,
  expect: {
    timeout: 15_000,
  },
  outputDir: process.env.ESTAB_E2E_ARTIFACT_DIR || 'test-results',
  reporter: process.env.CI
    ? [['line'], ['html', { open: 'never' }]]
    : [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        channel,
      },
    },
  ],
});
