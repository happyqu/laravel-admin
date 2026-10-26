const { test, expect } = require('@playwright/test');

test('renders the real admin layout, menu and content page', async ({ page }) => {
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

    await page.goto('http://127.0.0.1:4175/admin');

    await expect(page.locator('.app-wrapper')).toHaveCount(1);
    await expect(page.locator('.app-header')).toHaveCount(1);
    await expect(page.locator('.app-sidebar')).toHaveCount(1);
    await expect(page.locator('.sidebar-menu .nav-item')).toHaveCount(3);
    await expect(page.locator('.app-content-header h3')).toHaveText('Dashboard');
    await expect(page.locator('.card-body')).toContainText('Rendered by the real admin layout.');

    await page.locator('.sidebar-menu .nav-item').nth(1).locator('> .nav-link').click();
    await expect(page.locator('.sidebar-menu .nav-item').nth(1)).toHaveClass(/menu-open/);

    const bodyClass = await page.locator('body').getAttribute('class');
    expect(bodyClass).toContain('layout-fixed');
    expect(bodyClass).toContain('sidebar-expand-lg');

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
