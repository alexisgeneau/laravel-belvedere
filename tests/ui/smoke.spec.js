import { expect, test } from '@playwright/test';

/*
 * Every screen of the dashboard is opened against the seeded workbench
 * application and must render without a console error or a failed request.
 * The read-only checks come first; the ones that change data run last so
 * the counts asserted earlier stay predictable.
 */

test.describe.configure({ mode: 'serial' });

const problems = [];

test.beforeEach(async ({ page }) => {
    problems.length = 0;

    // The web font is served from a CDN: keep the test offline and quiet.
    await page.route('https://fonts.bunny.net/**', (route) => route.fulfill({ status: 200, contentType: 'text/css', body: '' }));

    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) {
            problems.push(`console.${message.type()}: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
    page.on('response', (response) => {
        if (response.status() >= 400) {
            problems.push(`HTTP ${response.status()} ${response.request().method()} ${response.url()}`);
        }
    });
});

test.afterEach(async () => {
    expect(problems, 'console errors or failed requests').toEqual([]);
});

const visit = (page, path) => page.goto(`/belvedere${path}`);
const rows = (page) => page.locator('table tbody tr');
const tab = (page, label) => page.locator('.nav-pills .nav-link', { hasText: label });

test('dashboard shows the workload, the workers and the lost executions', async ({ page }) => {
    await visit(page, '/dashboard');

    await expect(page).toHaveTitle('Dashboard - Belvedere');
    await expect(page.getByText('Jobs Per Minute')).toBeVisible();

    for (const queue of ['default', 'media', 'reports']) {
        await expect(page.locator('tr', { hasText: queue }).first()).toBeVisible();
    }

    await expect(page.locator('tr', { hasText: 'web-03:9112' }).locator('.badge', { hasText: 'Dead' })).toBeVisible();
    await expect(page.locator('a', { hasText: '(3 lost)' })).toBeVisible();
    await expect(page.getByText('(1 dead)')).toBeVisible();
});

test('monitoring lists the tags and their jobs', async ({ page }) => {
    await visit(page, '/monitoring');

    await expect(page.locator('tr', { hasText: 'billing' })).toBeVisible();
    await expect(page.locator('tr', { hasText: 'tenant:42' })).toBeVisible();

    await page.locator('tbody tr a', { hasText: 'billing' }).click();

    await expect(page).toHaveURL(/\/monitoring\/billing\/jobs$/);
    await expect(rows(page).first()).toContainText('SendInvoice');
});

test('metrics list the jobs and the queues and draw the charts', async ({ page }) => {
    await visit(page, '/metrics/jobs');
    await expect(page.locator('tbody tr a', { hasText: 'SendInvoice' })).toBeVisible();

    await visit(page, '/metrics/queues');
    await page.locator('tbody tr a', { hasText: 'default' }).first().click();

    await expect(page).toHaveURL(/\/metrics\/queue\/default$/);
    await expect(page.locator('canvas').first()).toBeVisible();

    await page.locator('.btn-group .btn', { hasText: '7 days' }).click();
    await expect(page.locator('canvas').first()).toBeVisible();
});

test('batches show their progress and their failed jobs', async ({ page }) => {
    await visit(page, '/batches');
    await page.locator('tbody tr a', { hasText: 'Nightly invoices' }).first().click();

    await expect(page.locator('h2', { hasText: 'Nightly invoices' })).toBeVisible();
    await expect(page.getByText('Failed Jobs').first()).toBeVisible();
    await expect(rows(page).first()).toContainText('ProcessPayment');
});

test('pending and delayed jobs come from the jobs table', async ({ page }) => {
    await visit(page, '/jobs/pending');
    await expect(rows(page).first()).toContainText('GenerateReport');

    await tab(page, 'Delayed').click();
    await expect(rows(page)).toHaveCount(2);
    await expect(page.locator('a[title="Run Now"]').first()).toBeVisible();
});

test('running and stuck jobs show their empty states', async ({ page }) => {
    await visit(page, '/jobs/running');
    await expect(page.getByText("There aren't any running jobs.")).toBeVisible();

    await tab(page, 'Stuck').click();
    await expect(page.getByText("There aren't any stuck jobs.")).toBeVisible();
});

test('completed jobs have their released, timed out and lost tabs', async ({ page }) => {
    await visit(page, '/jobs/completed');
    await expect(rows(page).first()).toBeVisible();

    await page.locator('tbody tr a').first().click();
    await expect(page.locator('h2', { hasText: 'Attempts' })).toBeVisible();
    await expect(page.locator('.badge', { hasText: 'Completed' }).first()).toBeVisible();

    await visit(page, '/jobs/completed');
    await expect(rows(page).first()).toBeVisible();
    await expect(page.locator('tbody tr', { hasText: 'RenderThumbnail' })).toHaveCount(0);

    await tab(page, 'Silenced').click();
    await expect(rows(page).first()).toContainText('RenderThumbnail');
    await expect(page.locator('tbody tr', { hasText: 'SendInvoice' })).toHaveCount(0);

    await tab(page, 'Released').click();
    await expect(rows(page).first()).toBeVisible();

    await tab(page, 'Timed Out').click();
    await expect(page.getByText("There aren't any timed out jobs.")).toBeVisible();

    await tab(page, 'Lost').click();
    await expect(page).toHaveURL(/status=lost$/);
    await expect(rows(page)).toHaveCount(3);

    await page.locator('tbody tr a', { hasText: 'SendInvoice' }).first().click();
    await expect(page.locator('h2', { hasText: 'Why the job was marked as lost' })).toBeVisible();
    await expect(page.getByText('Worker [web-03:9112] stopped responding while running this job.').first()).toBeVisible();
});

test('failed jobs can be searched and opened', async ({ page }) => {
    await visit(page, '/failed');
    await expect(rows(page).first()).toBeVisible();

    await page.getByPlaceholder(/Search/).fill('ProcessPayment');
    await expect(rows(page).first()).toContainText('ProcessPayment');

    await page.locator('tbody tr a').first().click();
    await expect(page.getByText('Payment gateway timed out').first()).toBeVisible();
});

test('workers list every process with its status', async ({ page }) => {
    await visit(page, '/workers');

    await expect(page.locator('tr', { hasText: 'web-03:9112' }).locator('.badge', { hasText: 'Dead' })).toBeVisible();
    await expect(page.locator('.badge', { hasText: 'Stopped' }).first()).toBeVisible();
});

test('the colour scheme can be switched', async ({ page }) => {
    await visit(page, '/dashboard');

    const dark = page.locator('style[data-scheme="dark"]');

    await expect(dark).toHaveJSProperty('media', 'max-width: 1px');
    await page.locator('button[title="Switch Theme"]').click();
    await expect(dark).toHaveJSProperty('media', '');
    await page.locator('button[title="Switch Theme"]').click();
    await expect(dark).toHaveJSProperty('media', 'max-width: 1px');
});

test('queues can be paused and resumed from the dashboard', async ({ page }) => {
    await visit(page, '/dashboard');

    const reports = page.locator('tr', { hasText: 'reports' }).first();

    await reports.locator('a[title="Pause Queue"]').click();
    await expect(reports.locator('.badge', { hasText: 'Paused' })).toBeVisible();

    await reports.locator('a[title="Resume Queue"]').click();
    await expect(reports.locator('.badge', { hasText: 'Paused' })).toHaveCount(0);
});

test('a failed job can be retried', async ({ page }) => {
    await visit(page, '/failed');

    const before = await rows(page).count();

    await rows(page).first().locator('a[title="Retry Job"]').click();
    await expect(rows(page)).toHaveCount(before - 1);
});

test('deleting a pending job marks its execution as lost', async ({ page }) => {
    await visit(page, '/jobs/pending');

    const before = await rows(page).count();
    const href = await rows(page).first().locator('a[title]').first().getAttribute('href');
    const uuid = href.split('/').pop();

    await rows(page).first().locator('a[title="Delete Job"]').click();
    await page.locator('#alertModal button', { hasText: 'Yes' }).click();
    await expect(rows(page)).toHaveCount(before - 1);

    await visit(page, '/jobs/completed?status=lost');
    await expect(rows(page)).toHaveCount(4);
    await expect(rows(page).first()).toContainText(uuid.split('-')[0]);

    await rows(page).first().locator('a').first().click();
    await expect(page.getByText('Deleted from the dashboard').first()).toBeVisible();
});
