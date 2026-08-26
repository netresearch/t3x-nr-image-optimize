import { defineConfig } from '@playwright/test';

/*
 * The suite is a benchmark, not a functional test: one worker, no retries,
 * no parallelism, so that nothing else competes for CPU while it measures.
 * The target URL comes from the runner (Build/Scripts/runTests.sh -s e2e).
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 40 * 60_000,
  reporter: [['list'], ['json', { outputFile: 'test-results/report.json' }]],
  use: {
    baseURL: process.env.BASE_URL || process.env.TYPO3_BASE_URL,
    ignoreHTTPSErrors: true,
    viewport: { width: 1280, height: 720 },
    deviceScaleFactor: 1,
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
