const { test, expect } = require('@playwright/test');

test('renders a field matrix with real Form field views and plugins', async ({ page }) => {
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

    await page.goto('http://127.0.0.1:4175/admin/fields');

    await expect(page.locator('.app-content-header h3')).toHaveText('Fields');
    await expect(page.locator('.admin-field')).not.toHaveCount(0);
    await expect(page.locator('input[name="name"]')).toHaveCount(1);
    await expect(page.locator('textarea[name="bio"]')).toHaveCount(1);
    await expect(page.locator('select[name="status"]')).toHaveCount(1);
    await expect(page.locator('select[name="roles[]"]')).toHaveCount(1);
    await expect(page.locator('input[name="permissions[]"]')).not.toHaveCount(0);
    await expect(page.locator('input[name="gender"]')).not.toHaveCount(0);
    await expect(page.locator('input[role="switch"]')).toHaveCount(1);
    await expect(page.locator('input[name="date"]')).toHaveCount(1);
    await expect(page.locator('input[name="start_date"]')).toHaveCount(1);
    await expect(page.locator('input[name="end_date"]')).toHaveCount(1);
    await expect(page.locator('input[name="published_at"]')).toHaveCount(1);
    await expect(page.locator('input[name="time"]')).toHaveCount(1);
    await expect(page.locator('.pcr-app')).not.toHaveCount(0);
    await expect(page.locator('input[name="number"]')).toHaveCount(1);
    await expect(page.locator('input[name="price"]')).toHaveCount(1);
    await expect(page.locator('input[name="amount"]')).toHaveCount(1);
    await expect(page.locator('input[name="mobile"]')).toHaveCount(1);
    await expect(page.locator('input[name="ip"]')).toHaveCount(1);
    await expect(page.locator('input[name="month"]')).toHaveCount(1);
    await expect(page.locator('input[name="year"]')).toHaveCount(1);
    await expect(page.locator('select[name="timezone"]')).toHaveCount(1);
    await expect(page.locator('input[name="hidden_field"]')).toHaveCount(1);
    await expect(page.locator('input[name="icon"]')).toHaveCount(1);
    await expect(page.locator('.alert-info')).toContainText('HTML field');
    await expect(page.locator('input[type="file"][name="file"]')).toHaveCount(1);
    await expect(page.locator('.select2-container')).not.toHaveCount(0);
    await expect(page.locator('.flatpickr-input')).not.toHaveCount(0);
    await expect(page.locator('.pcr-app')).not.toHaveCount(0);

    await expect(page.locator('.icheck')).toHaveCount(0);
    await expect(page.locator('.form-group')).toHaveCount(0);
    await expect(page.locator('.box-header')).toHaveCount(0);

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
