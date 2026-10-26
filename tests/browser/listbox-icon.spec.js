const { test, expect } = require('@playwright/test');

test('dual listbox and icon picker retain selection and submit field values', async ({ page }) => {
    await page.goto('http://127.0.0.1:4175/admin/fields');
    const box = page.locator('.admin-listbox');
    await expect(box).toBeVisible();
    await expect(page.locator('select[name="listbox[]"]')).toBeHidden();
    await box.locator('.admin-listbox-select').first().selectOption('b');
    await expect(box.locator('.admin-listbox-select').last().locator('option')).toHaveText(['B']);
    await box.locator('input').first().fill('C');
    await box.getByRole('button', {name: 'Add all filtered options', exact: true}).click();
    await expect(box.locator('.admin-listbox-select').last().locator('option')).toHaveText(['B', 'C']);
    await page.locator('input[name="icon"]').click();
    const picker = page.locator('.iconpicker-popover');
    await expect(picker).toBeVisible();
    await picker.locator('.iconpicker-search').fill('fa-user');
    await picker.locator('.iconpicker-item[title=".fa-user"]').click();
    await expect(page.locator('input[name="icon"]')).toHaveValue('fa-user');
    const data = await page.locator('select[name="listbox[]"]').evaluate(select => Array.from(new FormData(select.form).entries()).filter(([key]) => key === 'listbox[]' || key === 'icon'));
    expect(data).toContainEqual(['listbox[]', 'b']);
    expect(data).toContainEqual(['listbox[]', 'c']);
    expect(data).toContainEqual(['icon', 'fa-user']);
});
