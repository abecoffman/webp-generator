// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Scripted version of the manual browser run that first proved (this
 * project's own history) the long-reported "Scan does nothing" bug
 * didn't reproduce in a fresh session. Running it as a real, repeatable
 * spec means that finding stays proven, not just a one-time observation.
 */
test.describe('WebP Generator admin screen', () => {
	test('Scan runs to completion with no console errors', async ({ page }) => {
		const consoleErrors = [];
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				consoleErrors.push(msg.text());
			}
		});
		page.on('pageerror', (err) => consoleErrors.push(String(err)));

		await page.goto('/wp-admin/tools.php?page=webp-generator');
		await expect(page.getByRole('heading', { name: 'WebP Generator' })).toBeVisible();

		const scanButton = page.getByRole('button', { name: 'Scan', exact: true });
		const generateButton = page.getByRole('button', { name: 'Generate', exact: true });

		await expect(scanButton).toBeEnabled();
		await expect(generateButton).toBeDisabled();

		await scanButton.click();

		// A real batched AJAX scan against a bare sandbox library completes
		// almost instantly, but give it real headroom rather than assuming --
		// this is exactly the class of thing a fixed short wait would have
		// masked the original bug behind.
		await expect(scanButton).toBeDisabled();
		// The three real completion strings (see class-wwg-admin.php's
		// missingNone/missingSingular/missingPlural) -- a bare sandbox
		// library has nothing to convert, so missingNone is what a fresh
		// run should actually show.
		await expect(
			page.getByText(/already has a \.webp version|image is missing a \.webp version|images are missing a \.webp version/i)
		).toBeVisible({ timeout: 30000 });

		// Scan is no longer disabled after one run per page load -- its
		// result now persists server-side (the "Library Status" region),
		// so re-checking any time is safe and expected to work.
		await expect(scanButton).toBeEnabled();

		// A bare sandbox library has nothing to convert, so Generate may
		// correctly stay disabled -- the real assertion is that Scan
		// finished cleanly and reported a real, well-formed result.
		expect(consoleErrors).toEqual([]);
	});
});
