const { test, expect } = require('@playwright/test');

test('renders Box, InfoBox, Alert, Tab, Collapse and Carousel', async ({ page }) => {
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

    await page.goto('http://127.0.0.1:4175/admin/widgets');

    await expect(page.locator('.card-title')).toContainText('Box title');
    await expect(page.locator('.card-footer')).toContainText('Box footer');
    await expect(page.locator('.small-box')).toContainText('Users');
    await expect(page.locator('.small-box-footer')).toHaveCount(1);
    await expect(page.locator('.alert-danger')).toContainText('Alert content');

    await expect(page.locator('.nav-tabs .nav-link')).toHaveCount(2);
    await page.locator('.nav-tabs .nav-link').nth(1).click();
    await expect(page.locator('.tab-pane').nth(1)).toHaveClass(/active/);

    await expect(page.locator('.accordion')).toHaveCount(1);
    await expect(page.locator('.accordion-body')).toContainText('Collapse content');

    await expect(page.locator('.carousel')).toHaveCount(1);
    await expect(page.locator('.carousel-item')).toHaveCount(1);
    await expect(page.locator('.carousel-caption')).toContainText('Slide one');

    expect(errors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
