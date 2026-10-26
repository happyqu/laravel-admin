const { test, expect } = require('@playwright/test');

test('a confirmation hides existing page loading and restores it until the request ends', async ({ page }) => {
    let complete;
    await page.route('**/loading-probe', route => new Promise(resolve => {
        complete = async () => { await route.fulfill({ json: { status: true } }); resolve(); };
    }));
    await page.goto('http://127.0.0.1:4175/admin/form');
    await page.evaluate(() => { $.ajax({ url: '/loading-probe' }); });
    await expect(page.locator('.admin-ajax-loading')).toBeVisible();
    await page.evaluate(() => { Swal.fire({ title: 'Confirm?', showCancelButton: true }); });
    await expect(page.locator('.swal2-popup')).toBeVisible();
    await expect(page.locator('.admin-ajax-loading')).toBeHidden();
    await expect(page.locator('#nprogress')).toBeHidden();
    await page.evaluate(() => Swal.close());
    await expect(page.locator('.admin-ajax-loading')).toBeVisible();
    await complete();
    await expect(page.locator('.admin-ajax-loading')).toHaveCount(0);
});

test('requests started by confirmation do not cover the dialog loading controls', async ({ page }) => {
    await page.goto('http://127.0.0.1:4175/admin/form');
    await page.evaluate(() => {
        Swal.fire({
            title: 'Delete?', showLoaderOnConfirm: true,
            preConfirm: () => new Promise(resolve => {
                window.finishConfirmation = resolve;
                window.finishPageLoading = LA.startLoading();
            }),
        });
    });
    await page.locator('.swal2-confirm').click();
    await expect.poll(() => page.evaluate(() => Swal.isLoading())).toBe(true);
    await expect(page.locator('.admin-ajax-loading')).toHaveCount(1);
    await expect(page.locator('.admin-ajax-loading')).toBeHidden();
    await page.evaluate(() => { window.finishPageLoading(); window.finishConfirmation(true); });
    await expect(page.locator('.swal2-popup')).toHaveCount(0);
    await expect(page.locator('.admin-ajax-loading')).toHaveCount(0);
});
