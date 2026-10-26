const fs = require('node:fs');
const { defineConfig } = require('@playwright/test');

const defaultChrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const chromePath = process.env.CHROME_PATH || (fs.existsSync(defaultChrome) ? defaultChrome : undefined);
const useBundled = process.env.PLAYWRIGHT_USE_BUNDLED === '1';
const phpHost = process.platform === 'win32'
    ? 'php -d extension=pdo_sqlite -d extension=sqlite3'
    : 'php';

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
        ...(useBundled
            ? {}
            : chromePath
                ? { launchOptions: { executablePath: chromePath } }
                : { channel: 'chrome' }),
    },
    webServer: [
        {
            command: 'node tests/browser/server.js',
            port: 4173,
            reuseExistingServer: true,
            timeout: 15000,
        },
        {
            command: 'php -S 127.0.0.1:4174 tests/browser/login-server.php',
            port: 4174,
            reuseExistingServer: true,
            timeout: 15000,
        },
        {
            command: `${phpHost} -S 127.0.0.1:4175 tests/browser/host-server.php`,
            port: 4175,
            reuseExistingServer: true,
            timeout: 15000,
        },
    ],
});
