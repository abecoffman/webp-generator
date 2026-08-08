// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * E2E config for the plugin-sandbox WordPress install. Local-only --
 * see the sandbox test-suite plan (project memory) for why: a real WP
 * integration/E2E suite in CI would need its own containerized WP+MySQL,
 * out of scope here.
 *
 * Requires bin/reset-e2e-fixtures.sh to have restored the sandbox's
 * clean baseline before this runs -- `npm run test:e2e` does this
 * automatically via the pretest hook.
 */
module.exports = defineConfig({
	testDir: './tests/e2e',
	fullyParallel: false, // shared sandbox DB -- specs must not race each other.
	forbidOnly: !!process.env.CI,
	retries: 0,
	workers: 1,
	reporter: 'list',
	use: {
		baseURL: 'http://plugin-sandbox.test:8080',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /auth\.setup\.js/,
		},
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				storageState: 'tests/e2e/.auth/admin.json',
			},
			dependencies: ['setup'],
		},
	],
});
