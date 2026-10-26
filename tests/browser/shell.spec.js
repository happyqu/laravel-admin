const { test, expect } = require('@playwright/test');

async function box(page, selector) {
    return page.locator(selector).evaluate(element => {
        const rect = element.getBoundingClientRect();
        return {width: rect.width, height: rect.height, display: getComputedStyle(element).display};
    });
}

test.describe('AdminLTE 4 shell smoke test', () => {
    test('renders the shell and initializes AdminLTE controls', async ({ page }) => {
        const errors = [];
        page.on('console', message => {
            if (message.type() === 'error') {
                errors.push(message.text());
            }
        });

        await page.goto('/');
        await expect(page.locator('.app-wrapper')).toHaveCount(1);
        await expect(page.locator('.app-header')).toHaveCount(1);
        await expect(page.locator('.app-sidebar')).toHaveCount(1);
        await expect(page.locator('#admin-content')).toHaveCount(1);

        const layout = await box(page, '.app-wrapper');
        expect(layout.display).toBe('grid');

        expect((await box(page, '.app-header')).height).toBeGreaterThan(0);
        expect((await box(page, '.app-sidebar')).height).toBeGreaterThan(0);
        expect((await box(page, '#admin-content')).height).toBeGreaterThan(0);

        await page.locator('#tree-parent > .nav-link').click();
        await expect(page.locator('#tree-parent')).toHaveClass(/menu-open/);

        const initialBodyClass = await page.locator('body').getAttribute('class');
        await page.locator('#sidebar-toggle').click();
        await expect.poll(() => page.locator('body').getAttribute('class')).not.toBe(initialBodyClass);

        await page.locator('#card-collapse').click();
        await expect(page.locator('#test-card')).toHaveClass(/collapsed-card/);

        await page.locator('#open-modal').click();
        await expect(page.locator('#test-modal')).toHaveClass(/show/);
        await page.locator('#test-modal .btn-close').click();
        await expect(page.locator('#test-modal')).not.toHaveClass(/show/);

        await page.locator('#dropdown-button').click();
        await expect(page.locator('#dropdown-button + .dropdown-menu')).toHaveClass(/show/);

        await page.locator('#tab-two').click();
        await expect(page.locator('#pane-two')).toHaveClass(/active/);
        await expect(page.locator('#pane-one')).not.toHaveClass(/active/);

        expect(errors).toEqual([]);
    });

    test('keeps the sidebar usable on a mobile viewport', async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const page = await context.newPage();
        await page.goto('/');

        await expect(page.locator('.app-sidebar')).toHaveCount(1);
        await page.locator('#sidebar-toggle').click();
        await expect.poll(() => page.locator('body').getAttribute('class')).toMatch(/sidebar-open/);

        await context.close();
    });
});
