const { test, expect } = require('@playwright/test');

test('opens the real global navigation panel', async ({ page }) => {
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

    await page.goto('http://127.0.0.1:4175/admin/nav');

    await expect(page.locator('.app-content-header h3')).toHaveText('Navigation');
    await expect(page.locator('#dc-admin-nav-panel')).toHaveCount(1);
    await expect(page.locator('.sidebar-brand .brand-link')).toHaveCount(1);
    await expect(page.locator('body')).toHaveClass(/dc-admin-nav-mode-global/);
    await expect(page.locator('.dc-admin-nav-classic-sidebar .sidebar-wrapper')).toBeHidden();

    await page.locator('.sidebar-brand .brand-link').click();
    await expect(page.locator('#dc-admin-nav-panel')).toHaveClass(/is-open/);
    await expect(page.locator('#dc-admin-nav-search')).toHaveCount(1);
    await expect(page.locator('#dc-admin-nav-search')).toBeFocused();

    await page.locator('.dc-admin-nav-close').click();
    await expect(page.locator('#dc-admin-nav-panel')).not.toHaveClass(/is-open/);

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
