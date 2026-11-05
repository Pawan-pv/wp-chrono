# WordPress Plugin E2E & QA Engineering Standards

When asked to write, run, or debug End-to-End (E2E) tests for this WordPress plugin, strictly follow these industry standards:

## 1. Environment & Stack
- **Local WP Container:** Use `@wordpress/env` (`npx wp-env start`) on port `8888` with `WP_DEBUG`, `WP_DEBUG_LOG`, and `SCRIPT_DEBUG` enabled in `.wp-env.json`.
- **Test Runner:** Use `@playwright/test` and `@wordpress/e2e-test-utils-playwright` (`admin`, `editor`, `requestUtils`, `page` fixtures).
- **Subagent Delegation:** Use the `@general` subagent when scanning large directories to map hooks, shortcodes, REST routes, and Gutenberg blocks without polluting the main context window.

## 2. Selector & State Discipline
- **No Brittle Selectors:** Never use generated CSS classes or deep DOM paths. Use Playwright role/accessible locators (`page.getByRole()`, `page.getByLabel()`, `page.getByText()`) or add explicit `data-testid` attributes to plugin templates.
- **No Hard Sleeps:** Never use `page.waitForTimeout()`. Rely exclusively on web-first auto-retrying assertions (`await expect(locator).toBeVisible()`).
- **Atomic State Reset:** Reset state between specs using `requestUtils` or `npx wp-env run cli wp db reset --yes` so tests can run in any order.

## 3. Zero-Tolerance Telemetry Checks
- Every E2E spec must attach `page.on('pageerror', ...)` and `page.on('console', ...)` listeners to fail on uncaught JS errors originating from this plugin.
- After running Playwright specs, always inspect `/var/www/html/wp-content/debug.log` inside `wp-env` (`npx wp-env run cli tail -n 100 /var/www/html/wp-content/debug.log`) and fail the QA run if any `PHP Fatal error`, `PHP Warning`, or `PHP Deprecated` notice is triggered by this plugin.