const { test, expect } = require('@playwright/test');

test('renders real Form fields with AdminLTE 4 / Bootstrap 5 markup', async ({ page }) => {
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

    await page.goto('http://127.0.0.1:4175/admin/form');

    await expect(page.locator('.app-content-header h3')).toHaveText('Form');
    await expect(page.locator('.admin-field')).toHaveCount(5);
    await expect(page.locator('input[name="name"]')).toHaveValue('Alice');
    await expect(page.locator('input[name="email"]')).toHaveValue('alice@example.com');
    await expect(page.locator('select[name="status"]')).toHaveCount(1);
    await expect(page.locator('input[role="switch"]')).toHaveCount(1);
    await expect(page.locator('input[name="active"][type="hidden"]')).toHaveCount(1);
    await expect(page.locator('input[name="date"]')).toHaveCount(1);
    await expect(page.locator('.icheck')).toHaveCount(0);
    await expect(page.locator('.form-group')).toHaveCount(0);

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
