const { test, expect } = require('@playwright/test');

test('renders a real Grid with pagination and Filter without BS3 markup', async ({ page }) => {
    const errors = [];
    const failedRequests = [];

    page.on('console', message => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    page.on('requestfailed', request => failedRequests.push(request.url()));
    page.on('response', response => {
        if (response.status() >= 400) {
            failedRequests.push(`${response.status()} ${response.url()}`);
        }
    });

    await page.goto('http://127.0.0.1:4175/admin/grid');

    await expect(page.locator('.app-content-header h3')).toHaveText('Grid');
    await expect(page.locator('.grid-box')).toHaveCount(1);
    await expect(page.locator('.grid-box')).toHaveClass(/card/);
    await expect(page.locator('table.grid-table tbody tr')).toHaveCount(2);
    await expect(page.locator('table.grid-table')).toContainText('Alice');
    await expect(page.locator('table.grid-table')).toContainText('Bob');
    await expect(page.locator('.pagination .page-item')).not.toHaveCount(0);
    await expect(page.locator('.filter-box')).toHaveCount(1);
    await expect(page.locator('.filter-box input[name="name"]')).toHaveCount(1);

    await expect(page.locator('.form-group')).toHaveCount(0);
    await expect(page.locator('.box-header')).toHaveCount(0);
    await expect(page.locator('.pull-right')).toHaveCount(0);

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});

test('renders a real Show page with Bootstrap 5 labels and cards', async ({ page }) => {
    const errors = [];
    const failedRequests = [];

    page.on('console', message => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    page.on('response', response => {
        if (response.status() >= 400) {
            failedRequests.push(`${response.status()} ${response.url()}`);
        }
    });

    await page.goto('http://127.0.0.1:4175/admin/show');

    await expect(page.locator('.app-content-header h3')).toHaveText('Show');
    await expect(page.locator('.card')).not.toHaveCount(0);
    await expect(page.locator('.app-content')).toContainText('Alice');
    await expect(page.locator('.app-content')).toContainText('alice@example.com');
    await expect(page.locator('.col-form-label')).not.toHaveCount(0);
    await expect(page.locator('.control-label')).toHaveCount(0);
    await expect(page.locator('.box')).toHaveCount(0);

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
