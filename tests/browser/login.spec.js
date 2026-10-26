const { test, expect } = require('@playwright/test');

test('renders the real admin login Blade view', async ({ page }) => {
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

    await page.goto('http://127.0.0.1:4174/login');

    await expect(page).toHaveTitle(/Admin/);
    await expect(page.locator('.login-box')).toHaveCount(1);
    await expect(page.locator('.login-card-body')).toHaveCount(1);
    await expect(page.locator('input[name="username"]')).toHaveCount(1);
    await expect(page.locator('input[name="password"]')).toHaveCount(1);
    await expect(page.locator('button[type="submit"]')).toHaveCount(1);
    await expect(page.locator('input[name="remember"]')).toHaveAttribute('type', 'checkbox');
    await expect(page.locator('.icheck')).toHaveCount(0);

    const buttonColor = await page.locator('button[type="submit"]').evaluate(element => getComputedStyle(element).backgroundColor);
    expect(buttonColor).not.toBe('rgba(0, 0, 0, 0)');

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
