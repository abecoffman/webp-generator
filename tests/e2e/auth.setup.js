// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('path');

const authFile = path.join(__dirname, '.auth', 'admin.json');

/**
 * Logs into the sandbox once and saves the session, reused by every
 * other spec (standard Playwright Test pattern -- avoids a real login
 * flow, with its own flakiness surface, at the start of every test).
 * Credentials come from the environment, not hardcoded -- see
 * plugin-sandbox-wp-instance project memory for the sandbox's admin
 * password.
 */
setup('authenticate', async ({ page }) => {
	const user = process.env.SANDBOX_ADMIN_USER || 'admin';
	const pass = process.env.SANDBOX_ADMIN_PASS;

	if (!pass) {
		throw new Error('SANDBOX_ADMIN_PASS env var is not set -- see plugin-sandbox-wp-instance project memory for the sandbox admin password.');
	}

	await page.goto('/wp-login.php');
	await page.getByLabel('Username or Email Address').fill(user);
	await page.getByRole('textbox', { name: 'Password' }).fill(pass);
	await page.getByRole('button', { name: 'Log In' }).click();
	await expect(page).toHaveURL(/wp-admin\/?$/);

	await page.context().storageState({ path: authFile });
});
