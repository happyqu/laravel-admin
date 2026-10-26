const { test, expect } = require('@playwright/test');

test('renders real iframe tabs shell with a home tab', async ({ page }) => {
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

    await page.goto('http://127.0.0.1:4175/admin/iframe');

    await expect(page.locator('.content-tabs')).toHaveCount(1);
    await expect(page.locator('#tab-menu')).toHaveCount(1);
    await expect(page.locator('.page-tabs-content')).toHaveCount(1);
    await expect(page.locator('#tab-content')).toHaveCount(1);
    await expect(page.locator('#tab-content iframe.tab_iframe')).toHaveCount(1);
    await expect(page.locator('#tab-content iframe.tab_iframe')).toHaveAttribute('src', /\/admin\/dashboard$/);
    await expect(page.locator('.page-tabs-content a.menu_tab')).toHaveCount(1);
    await expect(page.locator('.page-tabs-content a.menu_tab')).toHaveText(/Home|Dashboard/);
    await expect(page.locator('.app-sidebar')).toHaveCount(1);

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
