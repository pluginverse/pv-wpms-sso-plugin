const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: __dirname,
  testMatch: '*.spec.cjs',
  workers: 1,
  timeout: 60000,
  // A cold environment takes several seconds for the first login and its redirect chain.
  expect: { timeout: 20000 },
  outputDir: '../../test-results',
  reporter: 'list',
  webServer: { cwd: require('node:path').resolve(__dirname, '../..'), command: 'node tests/Browser/https-proxy.cjs', url: 'https://127.0.0.1:9443/health', ignoreHTTPSErrors: true, reuseExistingServer: false },
  use: { browserName: 'chromium', ignoreHTTPSErrors: true, launchOptions: { args: ['--host-resolver-rules=MAP *.test 127.0.0.1', '--no-proxy-server'] } },
});
