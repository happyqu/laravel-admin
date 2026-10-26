const fs = require('node:fs');
const { defineConfig } = require('@playwright/test');

const defaultChrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const chromePath = process.env.CHROME_PATH || (fs.existsSync(defaultChrome) ? defaultChrome : undefined);

module.exports = defineConfig({
    testDir: './tests/browser',
    timeout: 30000,
    expect: { timeout: 5000 },
    fullyParallel: false,
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:4173',
        headless: true,
        viewport: { width: 1280, height: 800 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        ...(chromePath
            ? { launchOptions: { executablePath: chromePath } }
            : { channel: 'chrome' }),
    },
    webServer: {
        command: 'node tests/browser/server.js',
        port: 4173,
        reuseExistingServer: true,
        timeout: 15000,
    },
});
