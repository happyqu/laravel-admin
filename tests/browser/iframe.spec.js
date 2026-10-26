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

test('aligns extension links and tabs without overlapping the right toolbar', async ({ page }) => {
    await page.goto('http://127.0.0.1:4175/admin/iframe');
    await page.evaluate(() => {
        for (let i = 0; i < 12; i++) {
            window.addTabs({id: 'layout-' + i, title: 'Long tab title ' + i, content: '<div>Preview</div>', close: true});
        }
    });
    for (const width of [1440, 768, 390]) {
        await page.setViewportSize({width, height: 900});
        const layout = await page.evaluate(() => {
            const tabs = document.querySelector('.content-tabs').getBoundingClientRect();
            const toolbar = document.querySelector('.navbar-custom-menu').getBoundingClientRect();
            const links = Array.from(document.querySelectorAll('.app-header .navbar-nav > li > a')).map(link => {
                const rect = link.getBoundingClientRect();
                return {height: rect.height, center: rect.top + rect.height / 2};
            });
            return {overlap: tabs.right > toolbar.left + 1, overflow: document.documentElement.scrollWidth > innerWidth, links};
        });
        expect(layout.overlap).toBe(false);
        expect(layout.overflow).toBe(false);
        await expect.poll(() => page.evaluate(() => {
            const tabs = document.querySelector('.content-tabs').getBoundingClientRect();
            const active = document.querySelector('.menu_tab.active').getBoundingClientRect();
            return active.left >= tabs.left - 1 && active.right <= tabs.right + 1;
        })).toBe(true);
        for (const link of layout.links) {
            expect(link.height).toBe(40);
            expect(Math.abs(link.center - layout.links[0].center)).toBeLessThan(1);
        }
        await page.locator('.tab-options > a').click();
        await expect(page.locator('.tab-options > .dropdown-menu')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.locator('.tab-options > .dropdown-menu')).toBeHidden();
    }
});
