import { defineConfig, devices } from '@playwright/test';

/*
 * Smoke test of the dashboard against the workbench application: every
 * screen is opened on the seeded data and must render without a console
 * error or a failed request. `npm run test:ui` starts the server itself.
 */

const port = Number(process.env.BELVEDERE_UI_PORT ?? 8089);
const baseURL = `http://127.0.0.1:${port}`;

export default defineConfig({
    testDir: 'tests/ui',
    timeout: 30_000,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',

    use: {
        ...devices['Desktop Chrome'],
        baseURL,
        trace: 'retain-on-failure',
        // CHROMIUM_PATH points to a system Chromium when the Playwright build cannot be downloaded.
        launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
    },

    // The workbench is rebuilt and reseeded before every run so the counts asserted by the test hold.
    // BELVEDERE_UI_REUSE_SERVER=1 runs the test against a server you started yourself on the same port.
    webServer: {
        command: `php vendor/bin/testbench workbench:build && php vendor/bin/testbench serve --no-reload --tries=1 --port=${port}`,
        url: `${baseURL}/belvedere/api/overview`,
        reuseExistingServer: Boolean(process.env.BELVEDERE_UI_REUSE_SERVER),
        timeout: 180_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
