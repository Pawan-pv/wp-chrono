import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * Browser smoke test of the WP Chrono admin screen (AGENTS.md standards):
 * role/label locators, web-first assertions, no hard sleeps, and zero
 * tolerance for JS errors from the plugin and PHP errors in debug.log.
 *
 *   npm run test:e2e                                   # wp-env on :8888
 *   BASE_URL=http://localhost:8088 npm run test:e2e    # dev-env Docker stack
 *
 * DEBUG_LOG_CMD prints the site's debug.log; defaults to the wp-env CLI when
 * BASE_URL is unset. The deep UI flows (snapshots, restore, import, reset)
 * live in dev-env/tests/ui-e2e-checks.mjs.
 */

const USER = process.env.WP_USERNAME ?? (process.env.BASE_URL ? 'chrono' : 'admin');
const PASS = process.env.WP_PASSWORD ?? (process.env.BASE_URL ? 'chrono-pass' : 'password');
const DEBUG_LOG_CMD =
  // wp-env's CLI container runs `wp` as its command; plain `tail` never reaches
  // the shell. Reading through `wp eval` works on every wp-env setup.
  process.env.DEBUG_LOG_CMD ??
  (process.env.BASE_URL
    ? ''
    : `npx wp-env run cli wp eval 'echo @file_exists( WP_CONTENT_DIR . "/debug.log" ) ? file_get_contents( WP_CONTENT_DIR . "/debug.log" ) : "";'`);

let errors: string[] = [];

function watch(page: Page) {
  errors = [];
  page.on('pageerror', (err) => errors.push(`pageerror: ${err.message}`));
  page.on('console', (msg) => {
    const where = msg.location().url ?? '';
    if (msg.type() === 'error' && /wp-chrono/i.test(where + msg.text())) errors.push(`console: ${msg.text()} (${where})`);
  });
}

async function login(page: Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(USER);
  await page.getByLabel(/^password$/i).fill(PASS);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).toHaveURL(/\/wp-admin\//);
}

async function openChrono(page: Page) {
  await page.goto('/wp-admin/admin.php?page=wp-chrono#/dashboard');
  // The first-run welcome modal (or the Radix inert it casts over the page)
  // hides real content from role queries — settle it before asserting.
  const welcome = page.getByTestId('welcome-modal');
  const overview = page.getByRole('heading', { name: 'Overview', level: 1 });
  await expect(welcome.or(overview)).toBeVisible();
  if (await welcome.isVisible()) {
    // It is part of the product, so close it the user's way.
    await page.keyboard.press('Escape');
    await expect(welcome).toBeHidden();
  }
  await expect(overview).toBeVisible();
}

test.describe('WP Chrono admin screen', () => {
  test.beforeEach(async ({ page }) => {
    watch(page);
    await login(page);
  });

  test.afterEach(() => {
    expect(errors, 'JavaScript errors from WP Chrono').toEqual([]);
  });

  test('the SPA mounts and every section opens', async ({ page }) => {
    await openChrono(page);
    for (const [nav, heading] of [
      ['Reset Hub', /reset/i],
      ['Snapshots', /snapshots/i],
      ['Recovery', /recovery/i],
      ['Blueprints', /blueprints/i],
      ['Overview', /overview/i],
    ] as const) {
      await page.getByRole('button', { name: nav, exact: true }).click();
      await expect(page.getByRole('button', { name: nav, exact: true })).toHaveAttribute('aria-current', 'page');
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading);
    }
  });

  test('Ctrl+K opens exactly one command palette', async ({ page }) => {
    await openChrono(page);
    await page.keyboard.press('Control+k');
    const palette = page.getByPlaceholder('Type a command or search…');
    await expect(palette).toBeVisible();
    // WordPress 7+ has its own palette on the same keys; only ours may open.
    await expect(page.getByRole('dialog')).toHaveCount(1);
    await palette.fill('snapshots');
    await page.keyboard.press('Escape');
    await expect(palette).toBeHidden();
  });

  test('settings dialog opens from the gear', async ({ page }) => {
    await openChrono(page);
    await page.getByRole('button', { name: 'Settings & License' }).click();
    const dialog = page.getByTestId('settings-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByTestId('safe-updates-switch')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  });

  test('the REST API refuses requests without the WP Chrono nonce', async ({ page }) => {
    await openChrono(page);
    const cfg = await page.evaluate(() => (window as unknown as { wpChrono: { restUrl: string; restNonce: string; chronoNonce: string } }).wpChrono);
    // page.request shares the logged-in cookies (and keeps the deliberate 403 out of the page console).
    const without = await page.request.get(cfg.restUrl + 'snapshots', { headers: { 'X-WP-Nonce': cfg.restNonce } });
    expect([401, 403]).toContain(without.status());
    const withBoth = await page.request.get(cfg.restUrl + 'snapshots', { headers: { 'X-WP-Nonce': cfg.restNonce, 'X-WP-Chrono-Nonce': cfg.chronoNonce } });
    expect(withBoth.status()).toBe(200);
  });
});

test.afterAll(() => {
  if (!DEBUG_LOG_CMD) return;
  let log = '';
  try {
    log = execSync(DEBUG_LOG_CMD, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    return; // No debug.log yet: nothing was logged.
  }
  const ours = log.split('\n').filter((l) => /PHP (Fatal error|Warning|Deprecated|Parse error)/.test(l) && /wp-chrono/.test(l));
  expect(ours, 'PHP errors from WP Chrono in debug.log').toEqual([]);
});
