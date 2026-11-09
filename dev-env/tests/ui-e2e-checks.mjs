// Headless-browser E2E for the WP Chrono SPA — zero npm dependencies.
//
// Drives a locally installed Chrome / Edge / Playwright Chromium over the Chrome
// DevTools Protocol (Node's built-in WebSocket), with real mouse + keyboard
// events, against the dev stack. Screenshots go to tests/artifacts/.
//
//   node tests/ui-e2e-checks.mjs [base_url] [user] [password]
//
// DESTRUCTIVE (purges transients, restores a snapshot). Only run against dev-env.

import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.argv[2] ?? 'http://localhost:8088';
const USER = process.argv[3] ?? 'chrono';
const PASS = process.argv[4] ?? 'chrono-pass';
const PORT = 9300 + Math.floor(Math.random() * 500);
const RUN = Date.now().toString(36); // Unique per run, so leftovers from earlier runs never match.
const SNAP_LABEL = `E2E snapshot ${RUN}`;
const ARTIFACTS = path.join(path.dirname(fileURLToPath(import.meta.url)), 'artifacts');

let fails = 0;
let passes = 0;
const check = (label, ok, extra = '') => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${extra ? `  (${extra})` : ''}`);
  ok ? passes++ : fails++;
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Server-side fixtures run inside the dev container (tests/ui-fixtures.php).
const DEV_ENV = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const docker = (args) =>
  execFileSync('docker', ['compose', ...args], { cwd: DEV_ENV, encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' }, stdio: ['ignore', 'pipe', 'ignore'] });
const fixture = (action) => {
  const out = docker(['exec', '-T', 'wp', 'wp', 'eval-file', '/tests/ui-fixtures.php', action, '--user=chrono']);
  return Object.fromEntries([...out.matchAll(/^([A-Z]+)=(.*)$/gm)].map((m) => [m[1], m[2].trim()]));
};

// ---------------------------------------------------------------------------
// Browser + CDP plumbing
// ---------------------------------------------------------------------------
const local = process.env.LOCALAPPDATA ?? '';
const candidates = [
  process.env.CHROME_PATH,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  path.join(local, 'ms-playwright/chromium-1243/chrome-win/chrome.exe'),
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].filter(Boolean);
const browserPath = candidates.find((p) => existsSync(p));
if (!browserPath) {
  console.log('FAIL  no Chromium-based browser found');
  process.exit(1);
}

const profile = mkdtempSync(path.join(os.tmpdir(), 'wpc-e2e-'));
const browser = spawn(
  browserPath,
  [
    '--headless=new',
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${profile}`,
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-gpu',
    '--disable-extensions',
    '--window-size=1440,960',
    'about:blank',
  ],
  { stdio: 'ignore' },
);

async function cleanup() {
  browser.kill();
  await sleep(500);
  try {
    rmSync(profile, { recursive: true, force: true });
  } catch {
    /* Chrome may still hold the profile briefly on Windows. */
  }
}

async function devtools(pathname) {
  for (let i = 0; i < 100; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}${pathname}`);
      if (res.ok) return res.json();
    } catch {
      /* not up yet */
    }
    await sleep(100);
  }
  throw new Error('Browser DevTools endpoint did not come up');
}

class Page {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    this.listeners = [];
    this.consoleErrors = [];
    ws.addEventListener('message', (e) => {
      const msg = JSON.parse(e.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
      } else if (msg.method) {
        if (msg.method === 'Runtime.exceptionThrown') {
          this.consoleErrors.push(msg.params.exceptionDetails.exception?.description ?? msg.params.exceptionDetails.text);
        }
        if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
          this.consoleErrors.push(msg.params.args.map((a) => a.value ?? a.description).join(' '));
        }
        this.listeners.forEach((l) => l(msg));
      }
    });
  }
  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
  }
  waitEvent(method, timeout = 30000) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(`Timed out waiting for ${method}`)), timeout);
      const l = (msg) => {
        if (msg.method === method) {
          clearTimeout(timer);
          this.listeners = this.listeners.filter((x) => x !== l);
          resolve(msg.params);
        }
      };
      this.listeners.push(l);
    });
  }
  async eval(fn, ...args) {
    const expression = `(${fn})(...${JSON.stringify(args)})`;
    const res = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (res.exceptionDetails) throw new Error(res.exceptionDetails.exception?.description ?? res.exceptionDetails.text);
    return res.result.value;
  }
  async waitFor(fn, args = [], timeout = 20000, label = 'condition') {
    const end = Date.now() + timeout;
    let last;
    while (Date.now() < end) {
      try {
        last = await this.eval(fn, ...args);
        if (last) return last;
      } catch {
        /* page navigating */
      }
      await sleep(150);
    }
    throw new Error(`Timed out waiting for ${label}`);
  }
  async goto(url) {
    const loaded = this.waitEvent('Page.loadEventFired', 45000);
    await this.send('Page.navigate', { url });
    await loaded.catch((e) => {
      throw new Error(`${e.message} (navigating to ${url})`);
    });
    // The event may belong to the previous page; make sure we are on the new one.
    await this.waitFor((u) => location.href.split('#')[0] === u.split('#')[0] && document.readyState === 'complete', [url], 30000, `load of ${url}`);
  }
  async mouse(x, y, type, extra = {}) {
    await this.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1, ...extra });
  }
  /** Real pointer click on the first visible element matching text (and optional CSS scope). */
  async click(text, { scope = 'body', selector = 'button, a, [role="tab"], [cmdk-item], label', hold = 0 } = {}) {
    let box = await this.waitFor(findBox, [scope, selector, text], 20000, `"${text}"`);
    // Late-loading content can still move the target (a centred dialog grows when
    // its second request answers). A click at stale coordinates lands outside and
    // closes the dialog: click only once the box held still for two looks.
    for (let i = 0, still = 0; i < 40 && still < 2; i++) {
      await sleep(50);
      const again = await this.waitFor(findBox, [scope, selector, text], 5000, `"${text}"`);
      still = again.x === box.x && again.y === box.y ? still + 1 : 0;
      box = again;
    }
    await this.mouse(box.x, box.y, 'mouseMoved', { button: 'none' });
    await this.mouse(box.x, box.y, 'mousePressed');
    if (hold) await sleep(hold);
    await this.mouse(box.x, box.y, 'mouseReleased');
    return box;
  }
  async type(text) {
    await this.send('Input.insertText', { text });
  }
  async key(key, code, keyCode, modifiers = 0) {
    for (const type of ['keyDown', 'keyUp']) {
      await this.send('Input.dispatchKeyEvent', { type, key, code, windowsVirtualKeyCode: keyCode, modifiers });
    }
  }
  async screenshot(name) {
    const { data } = await this.send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(path.join(ARTIFACTS, `${name}.png`), Buffer.from(data, 'base64'));
  }
  text(scope = '#wp-chrono-root') {
    return this.eval((s) => document.querySelector(s)?.innerText ?? '', scope);
  }
  /** Focuses the input inside the <label> whose text starts with `label` (under `scope`), replaces its value by typing. */
  async fill(label, value, scope = '#wp-chrono-root') {
    await this.waitFor(
      (s, l) => {
        const el = [...document.querySelectorAll(`${s} label`)].find((x) => x.innerText.trim().startsWith(l))?.querySelector('input');
        if (!el) return false;
        el.focus();
        el.select();
        return true;
      },
      [scope, label],
      10000,
      `input "${label}"`,
    );
    await this.type(value);
  }
}

// Runs in the page: centre of the first visible element under `scope` matching `selector` whose text includes `text`.
function findBox(scope, selector, text) {
  const roots = [...document.querySelectorAll(scope)];
  for (const root of roots) {
    // Wait until open/close animations inside dialogs have settled, otherwise a
    // click can land on a box that is still scaling in.
    if (document.getAnimations().some((a) => a.playState === 'running')) return null;
    for (const el of root.querySelectorAll(selector)) {
      const label = (el.innerText || el.getAttribute('aria-label') || '').trim();
      if (!label.includes(text) || el.disabled) continue;
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      el.scrollIntoView({ block: 'center' });
      const s = el.getBoundingClientRect();
      return { x: s.x + s.width / 2, y: s.y + s.height / 2 };
    }
  }
  return null;
}

// ---------------------------------------------------------------------------
// Scenario
// ---------------------------------------------------------------------------
async function run() {
  mkdirSync(ARTIFACTS, { recursive: true });
  await devtools('/json/version');
  const target = (await devtools('/json/list')).find((t) => t.type === 'page');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  const page = new Page(ws);
  currentPage = page;
  await page.send('Page.enable');
  await page.send('Runtime.enable');
  console.log(`Browser: ${path.basename(browserPath)}\n`);

  // -- Login -----------------------------------------------------------------
  console.log('== Login & bootstrap ==');
  await page.goto(`${BASE}/wp-login.php`);
  await page.eval(
    (u, p) => {
      document.querySelector('#user_login').value = u;
      document.querySelector('#user_pass').value = p;
    },
    USER,
    PASS,
  );
  const afterLogin = page.waitEvent('Page.loadEventFired', 45000);
  await page.click('Log In', { selector: 'input[type="submit"], button' }).catch(async () => {
    await page.eval(() => document.querySelector('#loginform').submit());
  });
  // The wp-admin dashboard can embed external assets (welcome panel, news) that never
  // finish loading offline, so wait for the redirect rather than the load event.
  afterLogin.catch(() => {});
  await page.waitFor(() => location.pathname.includes('/wp-admin') && document.readyState !== 'loading', [], 45000, 'wp-admin after login');
  check('logged in to wp-admin', (await page.eval(() => location.pathname)).includes('/wp-admin'));

  await page.goto(`${BASE}/wp-admin/admin.php?page=wp-chrono&r=${Date.now()}#/dashboard`);
  // A late load event from the post-login dashboard can resolve goto() early; wait for the payload itself.
  const boot = await page.waitFor(() => window.wpChrono ?? null, [], 20000, 'wpChrono bootstrap').catch(() => null);
  check(
    'window.wpChrono bootstrap payload is complete',
    boot && ['restUrl', 'restNonce', 'chronoNonce', 'version', 'adminUrl'].every((k) => typeof boot[k] === 'string' && boot[k].length > 0),
    boot ? `v${boot.version}, restUrl ${boot.restUrl}` : 'missing',
  );
  await page.waitFor(() => /Database size[\s\S]*?\d+(\.\d+)? (KB|MB|GB)/.test(document.querySelector('#wp-chrono-root')?.innerText ?? ''), [], 20000, 'dashboard metrics');
  const dash = await page.text();
  check('dashboard renders live metrics (loading → data)', /Database size/.test(dash) && /Snapshots/.test(dash) && /Disk available/.test(dash));
  check('full-bleed shell: WP footer hidden, no horizontal scroll', await page.eval(() => getComputedStyle(document.querySelector('#wpfooter')).display === 'none' && document.documentElement.scrollWidth <= innerWidth));
  await page.screenshot('01-dashboard-light');

  // -- First-run onboarding (Phase 6) ----------------------------------------------
  console.log('\n== First-run onboarding ==');
  const downloads = path.join(ARTIFACTS, 'downloads');
  rmSync(downloads, { recursive: true, force: true });
  mkdirSync(downloads, { recursive: true });
  await page.send('Page.setDownloadBehavior', { behavior: 'allow', downloadPath: downloads });
  fixture('onboarding-reset');
  await page.goto(`${BASE}/wp-admin/admin.php?page=wp-chrono&r=${Date.now()}#/dashboard`);
  await page.waitFor(() => !!document.querySelector('[data-e2e="welcome-modal"] [data-e2e="checklist"]'), [], 15000, 'welcome modal');
  check('welcome modal appears once after activation, with the 3-step safety checklist', (await page.eval(() => document.querySelectorAll('[data-e2e="welcome-modal"] [data-e2e-step]').length)) === 3);
  await page.screenshot('21-welcome-modal');
  await page.click('Generate recovery script', { scope: '[data-e2e="welcome-modal"]' });
  await page.waitFor(() => !!document.querySelector('[data-e2e="kit-password"]'), [], 15000, 'recovery kit');
  const kitUrl = await page.eval(() => document.querySelector('[data-e2e="kit-url"]').innerText.trim());
  const kitPw = await page.eval(() => document.querySelector('[data-e2e="kit-password"]').innerText.trim());
  check('step 1 generates the recovery script and shows URL + one-time password', /wp-chrono-recovery-[a-z0-9]{16}\.php$/.test(kitUrl) && kitPw.length === 24, kitUrl);
  await page.click('Download recovery kit (.txt)', { scope: '[data-e2e="welcome-modal"]' });
  let kitFile = null;
  for (let i = 0; i < 40 && !kitFile; i++) {
    const hit = readdirSync(downloads).find((f) => /^wp-chrono-recovery-kit-.*\.txt$/.test(f));
    hit ? (kitFile = path.join(downloads, hit)) : await sleep(250);
  }
  const kitText = kitFile ? readFileSync(kitFile, 'utf8') : '';
  check('recovery kit .txt downloaded to the computer with URL, password and instructions', kitText.includes(kitUrl) && kitText.includes(kitPw) && /WHEN THE SITE IS DOWN/.test(kitText), kitFile ? path.basename(kitFile) : 'no file');
  await page.waitFor(() => document.querySelector('[data-e2e="welcome-modal"] [data-e2e-step="recovery"]')?.dataset.done === '1', [], 10000, 'step 1 done');
  check('step 1 marked done only after the kit was saved', true);
  const autoDone = await page.eval(() => document.querySelector('[data-e2e="welcome-modal"] [data-e2e-step="auto"]').dataset.done);
  if (autoDone !== '1') await page.click('Auto-snapshots', { scope: '[data-e2e="welcome-modal"]', selector: 'button[role="switch"]' });
  await page.waitFor(() => document.querySelector('[data-e2e="welcome-modal"] [data-e2e-step="auto"]')?.dataset.done === '1', [], 10000, 'step 2 done');
  check('step 2: auto-snapshots on (1-click toggle)', true);
  await page.click(await page.eval(() => (document.querySelector('[data-e2e="welcome-modal"]').innerText.includes('Done') ? 'Done' : 'Skip for now')), { scope: '[data-e2e="welcome-modal"]', selector: 'button' });
  await page.waitFor(() => !document.querySelector('[data-e2e="welcome-modal"]'), [], 5000, 'modal closed');
  await page.goto(`${BASE}/wp-admin/admin.php?page=wp-chrono&r=${Date.now()}#/dashboard`);
  await page.waitFor(() => !!document.querySelector('[data-e2e="safety-checklist"]'), [], 15000, 'checklist card');
  check('welcome modal never shows again; checklist card stays on the Overview', !(await page.eval(() => !!document.querySelector('[data-e2e="welcome-modal"]'))));
  await page.click('Dismiss checklist', { scope: '[data-e2e="safety-checklist"]', selector: 'button' });
  await page.waitFor(() => !document.querySelector('[data-e2e="safety-checklist"]'), [], 5000, 'dismissed');
  await page.goto(`${BASE}/wp-admin/admin.php?page=wp-chrono&r=${Date.now()}#/dashboard`);
  await page.waitFor(() => /Database size/.test(document.querySelector('#wp-chrono-root')?.innerText ?? ''), [], 15000, 'dashboard');
  await sleep(800);
  check('dismissing the checklist is permanent (zero nags)', !(await page.eval(() => !!document.querySelector('[data-e2e="safety-checklist"]'))));

  // -- Dark mode ---------------------------------------------------------------
  console.log('\n== Dark mode ==');
  const wasDark = await page.eval(() => document.querySelector('#wp-chrono-root').classList.contains('dark'));
  await page.click('Toggle theme');
  await page.waitFor((d) => document.querySelector('#wp-chrono-root').classList.contains('dark') !== d, [wasDark], 5000, 'theme toggle');
  if (wasDark) await page.click('Toggle theme');
  await page.waitFor(() => document.querySelector('#wp-chrono-root').classList.contains('dark'), [], 5000, 'dark');
  const bg = await page.eval(() => getComputedStyle(document.querySelector('#wp-chrono-root')).backgroundColor);
  const lum = bg.match(/\d+/g).slice(0, 3).reduce((a, b) => a + Number(b), 0);
  check('dark mode applies zinc-950 background', lum < 60, bg);
  check('theme preference persisted', (await page.eval(() => localStorage.getItem('wp-chrono-theme'))) === 'dark');
  await page.screenshot('02-dashboard-dark');

  // -- Command palette -----------------------------------------------------------
  console.log('\n== Command palette (Ctrl+K) ==');
  await page.key('k', 'KeyK', 75, 2 /* Ctrl */);
  await page.waitFor(() => !!document.querySelector('[cmdk-root]'), [], 5000, 'palette');
  const palette = await page.text('[cmdk-root]');
  check('Ctrl+K opens the palette', /Create Snapshot/.test(palette) && /Purge Transients/.test(palette) && /Full Site Reset/.test(palette));
  // WordPress 7+ has its own admin palette on the same keys (also cmdk): only ours may open here.
  const palettes = await page.eval(() => document.querySelectorAll('[cmdk-root]').length);
  check('only WP Chrono\'s palette opens (WordPress core palette stays closed)', palettes === 1, `${palettes} palette(s) open`);
  const before = await page.eval(() => document.querySelectorAll('[cmdk-item]').length);
  await page.type('scaveng');
  const firstItem = await page.waitFor(() => {
    const items = document.querySelectorAll('[cmdk-item]');
    return items.length > 0 && items.length < 4 ? items[0].innerText : null;
  }, [], 5000, 'filter');
  check('palette filters as you type (top hit ranked first)', firstItem.includes('Scan Orphaned Tables'), `${before} commands → "${firstItem.trim()}"`);
  await page.screenshot('03-command-palette');
  await page.key('Escape', 'Escape', 27);
  await page.waitFor(() => !document.querySelector('[cmdk-root]'), [], 5000, 'palette closed');
  check('Escape closes the palette', true);

  // -- Reset Hub: dry run + hold-to-confirm run --------------------------------
  console.log('\n== Reset Hub ==');
  await page.eval(() => (location.hash = '#/reset'));
  await page.waitFor(() => /Partial cleanup/.test(document.querySelector('#wp-chrono-root').innerText) && !document.querySelector('#wp-chrono-root .animate-pulse'), [], 20000, 'reset hub');
  const card = '#wp-chrono-root .rounded-xl:has(> div > div > div > div:first-child)';
  await page.eval(() => {
    // Seed a transient so the card has something to purge.
    return fetch(`${window.wpChrono.restUrl.replace('wp-chrono/v1/', 'wp/v2/settings')}`, { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.wpChrono.restNonce } });
  });
  const transientsCard = await page.eval(() => {
    const cards = [...document.querySelectorAll('#wp-chrono-root .rounded-xl')];
    const c = cards.find((el) => el.innerText.startsWith('Purge Transients') || el.innerText.includes('Purge Transients\n'));
    if (!c) return null;
    c.setAttribute('data-e2e', 'transients');
    return c.innerText;
  });
  check('partial cleanup cards render with live badges', !!transientsCard && /rows|Clean/.test(transientsCard), transientsCard?.split('\n').slice(0, 2).join(' · '));
  await page.click('Dry run', { scope: '[data-e2e="transients"]' });
  await page.waitFor(() => !!document.querySelector('[data-e2e="transients"] ul'), [], 10000, 'dry-run details');
  check('dry run shows what would be removed', (await page.text('[data-e2e="transients"] ul')).includes('wp_options'));
  await page.screenshot('04-reset-hub');
  void card;

  const runnable = await page.eval(() => !document.querySelector('[data-e2e="transients"] button:last-of-type')?.disabled);
  if (runnable) {
    await page.click('Run', { scope: '[data-e2e="transients"]' });
    await page.waitFor(() => /Hold to confirm/.test(document.querySelector('[role="alertdialog"]')?.innerText ?? ''), [], 5000, 'confirm dialog');
    check('destructive action opens AlertDialog with hold-to-confirm', true);
    await page.screenshot('05-confirm-dialog');
    await page.click('Hold to confirm', { scope: '[role="alertdialog"]', hold: 1800 });
    await page.waitFor(() => /Done: /.test(document.querySelector('[role="alertdialog"]')?.innerText ?? ''), [], 90000, 'partial run');
    const dlg = await page.text('[role="alertdialog"]');
    check('holding 1.5s executes: safety snapshot + batches + live log', /Safety snapshot #\d+/.test(dlg) && /Deleted \d+ transient rows/.test(dlg), dlg.match(/Safety snapshot #\d+[^\n]*/)?.[0]);
    await page.screenshot('06-partial-done');
    await page.click('Close', { scope: '[role="alertdialog"]' });
  } else {
    check('transients card runnable', false, 'nothing to purge');
  }

  // -- Snapshots: create (multi-tick) --------------------------------------------
  console.log('\n== Snapshots ==');
  const originalTitle = await page.eval(async () => (await (await fetch(`${location.origin}/?rest_route=/wp/v2/settings`, { headers: { 'X-WP-Nonce': window.wpChrono.restNonce } })).json()).title);
  await page.eval(() => (location.hash = '#/snapshots'));
  await page.waitFor(() => /Snapshots & Time Machine/.test(document.querySelector('#wp-chrono-root').innerText), [], 10000, 'snapshots view');
  await page.click('Create snapshot', { scope: '#wp-chrono-root > main' });
  await page.waitFor(() => !!document.querySelector('[role="dialog"] input'), [], 5000, 'create dialog');
  await page.type(SNAP_LABEL);
  await page.screenshot('07a-create-form');
  await page.click('Create snapshot', { scope: '[role="dialog"]', selector: 'button[type="submit"]' });
  await page.waitFor(() => /tables ·/.test(document.querySelector('[role="dialog"]')?.innerText ?? '') || !document.querySelector('[role="dialog"]'), [], 10000, 'progress');
  await page.screenshot('07-create-progress');
  await page.waitFor((l) => !document.querySelector('[role="dialog"]') && document.querySelector('#wp-chrono-root').innerText.includes(l), [SNAP_LABEL], 120000, 'snapshot created');
  check('Create Snapshot dialog runs chunked build with progress, row appears', true);
  const snapId = await page.eval((l) => {
    const li = [...document.querySelectorAll('#wp-chrono-root li')].find((el) => el.innerText.includes(l));
    li?.setAttribute('data-e2e', 'snap');
    return Number(li?.innerText.match(/#(\d+)/)?.[1]);
  }, SNAP_LABEL);
  const rowText = await page.text('[data-e2e="snap"]');
  check('timeline row shows ID, trigger badge, stats', snapId > 0 && /Manual/.test(rowText), `#${snapId}`);
  // Compare against the *server's* clock (Docker VMs drift from the host), and
  // make sure the UI isn't off by the timezone offset (hours).
  const clock = await page.eval(async (id) => {
    const res = await fetch(`${window.wpChrono.restUrl}snapshots`, { headers: { 'X-WP-Nonce': window.wpChrono.restNonce, 'X-WP-Chrono-Nonce': window.wpChrono.chronoNonce } });
    const created = (await res.json()).snapshots.find((x) => x.id === id).created_at;
    return { created, server: res.headers.get('date'), drift: Math.round((Date.now() - Date.parse(res.headers.get('date'))) / 1000) };
  }, snapId);
  const skew = Math.abs(Date.parse(clock.server) - Date.parse(clock.created)) / 1000;
  check('timestamps are UTC with zone (match the server clock, no timezone shift)', /Z$/.test(clock.created) && skew < 60 && !/hours? ago|days? ago/.test(rowText), `created ${clock.created}, ${Math.round(skew)}s before server time; host clock drift ${clock.drift}s`);

  // Change something so the diff has content (unique per run, so reruns still differ).
  const changedTitle = `E2E changed ${Date.now()}`;
  await page.eval(async (title) => {
    await fetch(`${location.origin}/?rest_route=/wp/v2/settings`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': window.wpChrono.restNonce, 'Content-Type': 'application/json' },
      body: JSON.stringify({ title }),
    });
  }, changedTitle);

  // -- Diff drawer --------------------------------------------------------------
  await page.click('Diff', { scope: '[data-e2e="snap"]' });
  await page.waitFor(() => /Compared in \d+ ms/.test(document.querySelector('[role="dialog"]')?.innerText ?? ''), [], 30000, 'diff');
  check('Diff drawer slides in and loads the comparison', /vs\. live site/.test(await page.text('[role="dialog"]')));
  await page.screenshot('08-diff-tables');
  await page.click('wp_options changes', { scope: '[role="dialog"]' });
  await page.waitFor(() => /Site title/.test(document.querySelector('[role="dialog"]')?.innerText ?? ''), [], 5000, 'options tab');
  const optionsTab = await page.text('[role="dialog"]');
  check('wp_options tab shows labelled before/after values', /Site title/.test(optionsTab) && optionsTab.includes(changedTitle) && optionsTab.includes(originalTitle));
  await page.screenshot('09-diff-options');

  // -- Restore from the drawer (atomic swap + nonce hot-swap) -------------------
  console.log('\n== Restore ==');
  const oldNonce = await page.eval(() => window.wpChrono.chronoNonce);
  await page.click('Restore this snapshot', { scope: '[role="dialog"]' });
  await page.waitFor(() => /Restore snapshot #/.test(document.querySelector('[role="alertdialog"]')?.innerText ?? ''), [], 5000, 'restore dialog');
  await page.eval(() => document.querySelector('#wpc-confirm').focus());
  await page.type('RESTORE');
  await page.click('Restore now', { scope: '[role="alertdialog"]' });
  await page.waitFor(() => /Restored snapshot #\d+/.test(document.querySelector('[role="alertdialog"]')?.innerText ?? ''), [], 180000, 'restore');
  const restoreLog = await page.text('[role="alertdialog"]');
  check('restore runs staging → atomic swap with live telemetry', /Atomic RENAME TABLE swap of \d+ tables took \d+ ms/.test(restoreLog), restoreLog.match(/Atomic RENAME[^\n]*/)?.[0]);
  await page.screenshot('10-restore-done');
  const newNonce = await page.eval(() => window.wpChrono.chronoNonce);
  check('fresh nonce hot-swapped into the SPA', !!newNonce && newNonce !== oldNonce, `${oldNonce} → ${newNonce}`);
  await page.click('Close', { scope: '[role="alertdialog"]' });

  await page.click('Refresh', { scope: '#wp-chrono-root > main' });
  await page.waitFor(() => !document.querySelector('#wp-chrono-root .animate-spin'), [], 15000, 'refresh');
  const after = await page.text();
  check('post-restore requests succeed (no 403 / session error)', !/session has expired|not allowed/i.test(after) && after.includes(SNAP_LABEL));
  const titleAfter = await page.eval(async () => (await (await fetch(`${location.origin}/?rest_route=/wp/v2/settings`, { headers: { 'X-WP-Nonce': window.wpChrono.restNonce } })).json()).title);
  check('restore rolled back the change made after the snapshot', titleAfter === originalTitle, `title "${titleAfter}"`);

  // -- Update rollback (Milestone 1) ------------------------------------------------
  console.log('\n== Update rollback (files + database) ==');
  const fx = fixture('rollback');
  const rollSnap = Number(fx.SNAP);
  check('real plugin update archived v1.0 with the auto-snapshot', rollSnap > 0 && fx.VERSION === '2.0', `#${rollSnap}, now v${fx.VERSION}`);
  await page.eval(() => (location.hash = '#/dashboard'));
  await page.eval(() => (location.hash = '#/snapshots'));
  await page.waitFor((id) => [...document.querySelectorAll('#wp-chrono-root li')].some((li) => li.innerText.includes(`#${id}`) && li.querySelector('[data-e2e-files]')), [rollSnap], 15000, 'auto-snapshot row');
  await page.eval((id) => [...document.querySelectorAll('#wp-chrono-root li')].find((li) => li.innerText.includes(`#${id}`))?.setAttribute('data-e2e', 'rollsnap'), rollSnap);
  const rollRow = await page.text('[data-e2e="rollsnap"]');
  check('row shows "1 folder archived", the version change and a Verified badge', /1 folder archived/.test(rollRow) && /wpc-ui-roll 1\.0 → 2\.0/.test(rollRow) && /Verified/.test(rollRow));
  await page.click('Undo update', { scope: '[data-e2e="rollsnap"]' });
  await page.waitFor(() => !!document.querySelector('[role="alertdialog"] [data-e2e="restore-mode"]'), [], 5000, 'restore mode choice');
  const modes = await page.eval(() => ({
    labels: [...document.querySelectorAll('[data-e2e="restore-mode"] label')].map((l) => l.innerText.split('\n')[0]),
    checked: document.querySelector('[data-e2e="restore-mode"] input:checked')?.value,
  }));
  check('restore dialog offers Full Undo / Database Only / Files Only (Full Undo preselected)', modes.labels.length === 3 && /Full Undo/.test(modes.labels[0]) && modes.labels[1] === 'Database Only' && modes.labels[2] === 'Files Only' && modes.checked === 'full', modes.labels.join(' | '));
  await page.screenshot('15-undo-update-dialog');
  await page.eval(() => document.querySelector('#wpc-confirm').focus());
  await page.type('RESTORE');
  await page.click('Restore now', { scope: '[role="alertdialog"]' });
  await page.waitFor(() => /plugin\/theme folder\(s\) reverted/.test(document.querySelector('[role="alertdialog"]')?.innerText ?? ''), [], 180000, 'full undo');
  const undoLog = await page.text('[role="alertdialog"]');
  check('Full Undo: atomic table swap, then folder swap, logged live', /Atomic RENAME TABLE swap/.test(undoLog) && /Reverted plugin wpc-ui-roll to v1\.0/.test(undoLog));
  check('plugin files on disk are back to v1.0', fixture('version').VERSION === '1.0');
  await page.screenshot('16-undo-update-done');
  await page.click('Close', { scope: '[role="alertdialog"]' });

  // -- Storage warning (Milestone 2) ----------------------------------------------------
  console.log('\n== Storage warning ==');
  const setWarn = (mb) =>
    page.eval(async (v) => {
      await fetch(`${window.wpChrono.restUrl}settings`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': window.wpChrono.restNonce, 'X-WP-Chrono-Nonce': window.wpChrono.chronoNonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ storage_warn_mb: v }),
      });
    }, mb);
  fixture('rollback'); // A second auto-snapshot, so there is an older one to prune.
  await setWarn(1);
  await page.eval(() => (location.hash = '#/snapshots'));
  await page.eval(() => (location.hash = '#/dashboard'));
  await page.waitFor(() => /Prune old auto-snapshots/.test(document.querySelector('[data-e2e="storage-warning"]')?.innerText ?? ''), [], 15000, 'storage warning');
  const warn = await page.text('[data-e2e="storage-warning"]');
  check('dashboard shows the bloat warning with a 1-click prune', /warning threshold/.test(warn) && /Prune old auto-snapshots \(\d+ ·/.test(warn), warn.split('\n')[0].slice(0, 90));
  await page.screenshot('17-storage-warning');
  await page.click('Prune old auto-snapshots', { scope: '[data-e2e="storage-warning"]' });
  await page.waitFor(() => /Pruned \d+ old auto-snapshot/.test(document.querySelector('[data-e2e="storage-warning"]')?.innerText ?? ''), [], 30000, 'pruned');
  check('Prune deletes the older auto-snapshot and reports the new usage', true, (await page.text('[data-e2e="storage-warning"]')).split('\n')[0].slice(0, 90));
  await setWarn(500);

  // -- Settings & License (Phase 6) -------------------------------------------------------
  console.log('\n== Settings & License ==');
  await page.click('Settings & License', { selector: 'button' });
  await page.waitFor(() => !!document.querySelector('[data-e2e="settings-dialog"] input[aria-label="Rollback archive size limit (MB)"]')?.value, [], 10000, 'settings dialog');
  await page.eval(() => {
    const el = document.querySelector('[data-e2e="settings-dialog"] input[aria-label="Rollback archive size limit (MB)"]');
    el.focus();
    el.select();
  });
  await page.type('300');
  await page.screenshot('25a-settings-open');
  await page.eval(() => (window.__wpcBeforeSave = 1));
  // Retry the save up to 3 times: the Radix Dialog can occasionally close on
  // save click (overlay interaction), in which case we re-open and try again.
  let settingsSaved = false;
  let lastError = null;
  for (let attempt = 0; attempt < 3 && !settingsSaved; attempt++) {
    if (attempt > 0) {
      await page.click('Settings & License', { selector: 'button' });
      await page.waitFor(() => !!document.querySelector('[data-e2e="settings-dialog"] input[aria-label="Rollback archive size limit (MB)"]')?.value, [], 10000, 'settings dialog retry');
      await page.eval(() => {
        const el = document.querySelector('[data-e2e="settings-dialog"] input[aria-label="Rollback archive size limit (MB)"]');
        el.focus();
        el.select();
      });
      await page.type('300');
      await page.eval(() => (window.__wpcBeforeSave = 1));
    }
    const saveBox = await page.eval(() => {
      const btn = document.querySelector('[data-e2e="settings-save"]');
      if (!btn) throw new Error('Save settings button not found');
      btn.click();
      const r = btn.getBoundingClientRect();
      return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    await page.screenshot('25b-settings-after-click');
    try {
      await page.waitFor(() => /Settings saved/.test(document.querySelector('[data-e2e="settings-message"]')?.innerText ?? ''), [], 10000, 'settings saved');
      settingsSaved = true;
    } catch (e) {
      lastError = e;
      // Capture JS errors for diagnosis
      const jsErrors = page.consoleErrors.slice(-5);
      // Explain the failure: reload, dialog closed, or an error message instead.
      const state = await page.eval(() => ({
        reloaded: !window.__wpcBeforeSave,
        dialog: !!document.querySelector('[data-e2e="settings-dialog"]'),
        message: document.querySelector('[data-e2e="settings-message"]')?.innerText ?? null,
        tab: document.querySelector('[data-e2e="settings-dialog"] [role="tab"][aria-selected="true"]')?.innerText ?? null,
        hash: location.hash,
      }));
      if (attempt === 2) {
        throw new Error(`${lastError.message} — ${JSON.stringify({ ...state, clickedAt: saveBox, jsErrors })}`);
      }
      // Brief pause before retry
      await new Promise((r) => setTimeout(r, 500));
    }
  }
  const saved = await page.eval(async () => (await (await fetch(`${window.wpChrono.restUrl}settings`, { headers: { 'X-WP-Nonce': window.wpChrono.restNonce, 'X-WP-Chrono-Nonce': window.wpChrono.chronoNonce } })).json()).rollback_max_mb);
  check('Settings tab saves the rollback archive size limit', saved === 300, `${saved} MB`);
  await page.key('Escape', 'Escape', 27);
  await page.waitFor(() => !document.querySelector('[data-e2e="settings-dialog"]'), [], 5000, 'settings closed');

  fixture('license-off');
  const testKey = fixture('license-key').KEY;
  await page.goto(`${BASE}/wp-admin/admin.php?page=wp-chrono&r=${Date.now()}#/blueprints`);
  // The cloud panel renders its card once its settings have loaded.
  await page.waitFor(() => document.querySelectorAll('[data-e2e="pro-lock"]').length === 2, [], 15000, 'pro lock cards');
  const lockedText = await page.text();
  check('without a license: one inline Pro card per locked feature, no Capture button', !/Capture current site/.test(lockedText) && (await page.eval(() => document.querySelectorAll('[data-e2e="pro-lock"]').length)) === 2, 'Blueprints + Cloud');
  await page.eval(() => (location.hash = '#/snapshots'));
  await page.waitFor(() => /Snapshots & Time Machine/.test(document.querySelector('#wp-chrono-root').innerText), [], 10000, 'snapshots');
  check('no Pro nag anywhere else (Snapshots screen is clean)', !(await page.eval(() => !!document.querySelector('[data-e2e="pro-lock"]') || /Pro feature|Upgrade/i.test(document.querySelector('#wp-chrono-root').innerText))));
  await page.eval(() => (location.hash = '#/blueprints'));
  await page.waitFor(() => !!document.querySelector('[data-e2e="pro-lock"]'), [], 10000, 'pro lock again');
  await page.screenshot('22-pro-locked');
  await page.click('Enter license', { scope: '[data-e2e="pro-lock"]' });
  await page.waitFor(() => !!document.querySelector('[data-e2e="license-tab"]'), [], 5000, 'license tab');
  check('"Enter license" opens Settings → License with the Free-forever list', /Free forever/.test(await page.text('[data-e2e="license-tab"]')) && /Every restore/.test(await page.text('[data-e2e="license-tab"]')));
  await page.fill('License key', testKey, '[data-e2e="license-tab"]');
  await page.click('Activate', { scope: '[data-e2e="license-tab"]' });
  await page.waitFor(() => /License activated/.test(document.querySelector('[data-e2e="settings-message"]')?.innerText ?? ''), [], 10000, 'activated');
  check('Ed25519 key activates in the UI (plan: pro)', (await page.text('[data-e2e="license-plan"]')).trim().toLowerCase() === 'pro');
  await page.screenshot('23-license-active');
  await page.key('Escape', 'Escape', 27);
  await page.waitFor(() => !document.querySelector('[data-e2e="pro-lock"]') && /Capture current site/.test(document.querySelector('#wp-chrono-root').innerText), [], 10000, 'unlocked');
  check('Blueprints + Cloud unlock live, without a page reload', true);

  // -- Import .sql.gz (Phase 6) -------------------------------------------------------------
  console.log('\n== Import .sql.gz ==');
  const exportPath = fixture('export-file').PATH;
  const localDump = path.join(downloads, 'e2e-import.sql.gz');
  writeFileSync(localDump, execFileSync('docker', ['compose', 'exec', '-T', 'wp', 'cat', exportPath], { cwd: DEV_ENV, env: { ...process.env, MSYS_NO_PATHCONV: '1' }, maxBuffer: 1 << 28 }));
  const dumpBytes = readFileSync(localDump).length;
  check('export fixture is larger than a stock nginx body limit (1 MiB)', dumpBytes > 1048576, `${dumpBytes} bytes`);
  await page.eval(() => (location.hash = '#/snapshots'));
  await page.waitFor(() => /Import \.sql\.gz/.test(document.querySelector('#wp-chrono-root').innerText), [], 15000, 'snapshots');
  const exportedHref = await page.waitFor(() => document.querySelector('#wp-chrono-root a[href*="wp_chrono_download"]')?.getAttribute('href'), [], 15000, 'download link');
  const dl = await page.eval(async (href) => {
    const r = await fetch(href, { credentials: 'same-origin' });
    const b = new Uint8Array(await r.arrayBuffer());
    return { status: r.status, type: r.headers.get('content-type'), gzip: b[0] === 0x1f && b[1] === 0x8b, bytes: b.length, head: new TextDecoder().decode(b.slice(0, 60)) };
  }, exportedHref);
  check('".sql.gz" download link in a snapshot row actually downloads the gzip', dl.status === 200 && dl.gzip, `HTTP ${dl.status}, ${dl.type}, ${dl.bytes} bytes${dl.gzip ? '' : `, starts "${dl.head.replace(/\s+/g, ' ')}", href ${exportedHref.slice(0, 120)}`}`);
  await page.click('Import .sql.gz', { scope: '#wp-chrono-root > main', selector: 'button' });
  await page.waitFor(() => !!document.querySelector('[data-e2e="import-file"]'), [], 5000, 'import dialog');
  const { root } = await page.send('DOM.getDocument', { depth: -1 });
  const { nodeId } = await page.send('DOM.querySelector', { nodeId: root.nodeId, selector: '[data-e2e="import-file"]' });
  await page.send('DOM.setFileInputFiles', { nodeId, files: [localDump] });
  await page.waitFor(() => /e2e-import\.sql\.gz/.test(document.querySelector('[role="dialog"]')?.innerText ?? ''), [], 5000, 'file chosen');
  await page.click('Upload & import', { scope: '[role="dialog"]', selector: 'button' });
  await page.waitFor(() => /Imported .* as snapshot #\d+/.test(document.querySelector('#wp-chrono-root').innerText), [], 120000, 'imported');
  const importedMsg = (await page.text()).match(/Imported [^\n]*/)?.[0] ?? '';
  check('Import .sql.gz dialog: upload → validate → verified snapshot', /verified/.test(importedMsg), importedMsg.slice(0, 90));
  const importedRow = await page
    .waitFor(() => [...document.querySelectorAll('#wp-chrono-root li')].find((li) => /Imported: E2E export source/.test(li.innerText) && /Verified/.test(li.innerText))?.innerText, [], 15000, 'imported row')
    .catch(() => page.eval(() => [...document.querySelectorAll('#wp-chrono-root li')].find((li) => /Imported: E2E export source/.test(li.innerText))?.innerText ?? 'row not found'));
  check('imported snapshot appears with the "Imported" badge, ready to restore', /Imported/.test(importedRow) && /Restore/.test(importedRow) && /Verified/.test(importedRow));
  await page.screenshot('24-imported-snapshot');

  // -- Blueprints (Milestone 3) -----------------------------------------------------------
  console.log('\n== Blueprints ==');
  const BP_NAME = `E2E baseline ${RUN}`;
  await page.eval(() => (location.hash = '#/blueprints'));
  await page.waitFor(() => /Blueprints & Cloud/.test(document.querySelector('#wp-chrono-root').innerText) && !!document.querySelector('[data-e2e="cloud-panel"]'), [], 15000, 'blueprints view');
  check('Blueprints view replaces the placeholder (no "Not built yet")', !/Not built yet/.test(await page.text()));
  await page.click('Capture current site', { scope: '#wp-chrono-root > main' });
  await page.waitFor(() => !!document.querySelector('[role="dialog"] input'), [], 5000, 'capture dialog');
  await page.type(BP_NAME);
  await page.click('Capture blueprint', { scope: '[role="dialog"]', selector: 'button[type="submit"]' });
  await page.waitFor((n) => !document.querySelector('[role="dialog"]') && !!document.querySelector(`[data-e2e-blueprint="${n}"]`), [BP_NAME], 60000, 'blueprint captured');
  const notice = await page.text('[data-e2e="blueprint-notice"]');
  check('capture dialog saves plugins, theme, settings + bundled zips', /Captured ".*": \d+ plugins, \d+ theme\(s\), \d+ settings, .* of zips/.test(notice), notice.slice(0, 100));
  await page.click('Show contents', { scope: `[data-e2e-blueprint="${BP_NAME}"]`, selector: 'button' });
  await page.waitFor((n) => /Add plugin \/ theme \.zip/.test(document.querySelector(`[data-e2e-blueprint="${n}"]`).innerText), [BP_NAME], 5000, 'contents');
  const contents = await page.text(`[data-e2e-blueprint="${BP_NAME}"]`);
  check('expanded blueprint lists items with source badges and zip upload', /zip/.test(contents) && /active/.test(contents));
  await page.screenshot('18-blueprints');

  const nonceBeforeBaseline = await page.eval(() => window.wpChrono.chronoNonce);
  fixture('permalink-set'); // Self-contained: do not depend on prod/start.sh setting pretty permalinks.
  const permBefore = fixture('permalink').PERMALINK ?? '';
  check('site starts with pretty permalinks', permBefore.includes('%postname%'), permBefore);
  await page.click('Reset to Dev Baseline', { scope: `[data-e2e-blueprint="${BP_NAME}"]` });
  await page.waitFor(() => /Reset to Dev Baseline:/.test(document.querySelector('[role="alertdialog"]')?.innerText ?? ''), [], 5000, 'baseline dialog');
  await page.eval(() => document.querySelector('#wpc-confirm').focus());
  await page.type('RESET');
  await page.click('Reset to baseline', { scope: '[role="alertdialog"]' });
  await page.waitFor(() => /Site rebuilt from blueprint|failed step/.test(document.querySelector('[role="alertdialog"]')?.innerText ?? ''), [], 240000, 'baseline applied');
  const baselineLog = await page.text('[role="alertdialog"]');
  check('Reset to Dev Baseline: safety snapshot → full reset → every step ✓', /Site rebuilt from blueprint/.test(baselineLog) && /✓ Apply \d+ baseline settings/.test(baselineLog) && !/✗/.test(baselineLog), baselineLog.match(/Site rebuilt[^\n]*/)?.[0]);
  await page.screenshot('19-baseline-done');
  check('session survives the baseline reset (fresh nonce hot-swapped)', (await page.eval(() => window.wpChrono.chronoNonce)) !== nonceBeforeBaseline);
  // REST must work immediately after a full reset on a pretty-permalink site:
  // the option survives the reset, and both the SPA base (rest_url()) and
  // the pretty /wp-json/… form return JSON (not the homepage).
  const permAfter = fixture('permalink').PERMALINK ?? '';
  check('permalink_structure preserved across the full reset (still pretty)', permAfter === permBefore && permAfter.includes('%postname%'), permAfter);
  const nonceWorks = await page.eval(async () => {
    const r = await fetch(window.wpChrono.restUrl + 'nonce', { headers: { 'X-WP-Nonce': window.wpChrono.restNonce, 'X-WP-Chrono-Nonce': window.wpChrono.chronoNonce } });
    if (!r.ok) return false;
    try {
      const j = await r.json();
      return !!(j.rest && j.chrono);
    } catch {
      return false;
    }
  });
  check('REST calls work right after full reset (nonce round-trip via SPA base)', nonceWorks);
  const prettyWorks = await page.eval(async () => {
    const r = await fetch(`${location.origin}/wp-json/wp-chrono/v1/nonce`, { headers: { 'X-WP-Nonce': window.wpChrono.restNonce, 'X-WP-Chrono-Nonce': window.wpChrono.chronoNonce } });
    if (!r.ok) return false;
    try {
      const j = await r.json();
      return !!(j.rest && j.chrono);
    } catch {
      return false;
    }
  });
  check('pretty /wp-json/… routing still works after reset (permalink preserved)', prettyWorks);
  await page.click('Close', { scope: '[role="alertdialog"]' });

  // -- Cloud (Milestone 3) ----------------------------------------------------------------
  console.log('\n== Cloud storage ==');
  docker(['exec', '-T', 'wp', 'sh', '-c', 'pkill -f "[m]ock-s3.php"; rm -rf /tmp/mock-s3; mkdir -p /tmp/mock-s3/data; true']);
  docker(['exec', '-d', 'wp', 'sh', '-c', 'php -S 127.0.0.1:9000 /tests/mock-s3.php >/tmp/mock-s3/server.log 2>&1']);
  await sleep(800);
  const panel = await page.text('[data-e2e="cloud-panel"]');
  check('cloud panel offers R2 / S3 / B2 presets and encrypted-secret note', /Cloudflare R2/.test(panel) && /Amazon S3/.test(panel) && /Backblaze B2/.test(panel) && /encrypted at rest/.test(panel));
  await page.click('S3-compatible', { scope: '[data-e2e="cloud-panel"]', selector: 'button' });
  await page.fill('Endpoint URL', 'http://127.0.0.1:9000', '[data-e2e="cloud-panel"]');
  await page.fill('Bucket', 'Bad_Bucket', '[data-e2e="cloud-panel"]');
  await page.click('Save', { scope: '[data-e2e="cloud-panel"]', selector: 'button' });
  await page.waitFor(() => /Bucket names/.test(document.querySelector('[data-e2e="cloud-result"]')?.innerText ?? ''), [], 10000, 'validation');
  check('invalid bucket name rejected inline', true);
  await page.fill('Bucket', 'wpc-test-bucket', '[data-e2e="cloud-panel"]');
  await page.fill('Region', 'us-east-1', '[data-e2e="cloud-panel"]');
  await page.fill('Access key ID', 'MOCKACCESSKEY', '[data-e2e="cloud-panel"]');
  await page.fill('Secret access key', 'mock/Secret+Key/0123456789abcdefghij', '[data-e2e="cloud-panel"]');
  await page.click('Save', { scope: '[data-e2e="cloud-panel"]', selector: 'button' });
  await page.waitFor(() => /Saved/.test(document.querySelector('[data-e2e="cloud-result"]')?.innerText ?? ''), [], 10000, 'saved');
  await page.waitFor(() => /Connected/.test(document.querySelector('[data-e2e="cloud-panel"]').innerText), [], 10000, 'connected badge');
  const secretShown = await page.eval(() => document.body.innerHTML.includes('mock/Secret+Key'));
  check('secret saved; never rendered back into the page', !secretShown);
  await page.click('Test connection', { scope: '[data-e2e="cloud-panel"]' });
  await page.waitFor(() => /Connected to wpc-test-bucket/.test(document.querySelector('[data-e2e="cloud-result"]')?.innerText ?? ''), [], 20000, 'test connection');
  check('Test connection: list + write + read + delete against the bucket', true, (await page.text('[data-e2e="cloud-result"]')).trim());
  await page.screenshot('20-cloud-panel');

  await page.eval(() => (location.hash = '#/snapshots'));
  await page.waitFor((l) => document.querySelector('#wp-chrono-root').innerText.includes(l) && /Push/.test(document.querySelector('#wp-chrono-root').innerText), [SNAP_LABEL], 15000, 'push buttons');
  await page.eval((l) => [...document.querySelectorAll('#wp-chrono-root li')].find((li) => li.innerText.includes(l))?.setAttribute('data-e2e', 'snap'), SNAP_LABEL);
  await page.click('Push', { scope: '[data-e2e="snap"]' });
  await page.waitFor(() => /Pushed snapshot #\d+/.test(document.querySelector('#wp-chrono-root').innerText), [], 120000, 'push');
  check('Push from a snapshot row: export → upload → manifest', true, (await page.text()).match(/Pushed snapshot #[^\n]*/)?.[0]);

  await page.eval(() => (location.hash = '#/blueprints'));
  await page.waitFor((l) => (document.querySelector('[data-e2e="remote-backups"]')?.innerText ?? '').includes(l), [SNAP_LABEL], 20000, 'remote list');
  check('remote backups list shows the pushed snapshot', true);
  await page.click('Pull', { scope: '[data-e2e="remote-backups"]' });
  await page.waitFor(() => /Pulled as snapshot #\d+/.test(document.querySelector('[data-e2e="blueprint-notice"]')?.innerText ?? ''), [], 120000, 'pull');
  const pulledMsg = await page.text('[data-e2e="blueprint-notice"]');
  check('Pull rebuilds it as a new verified local snapshot', /verified/.test(pulledMsg), pulledMsg.match(/Pulled as snapshot #\d+[^.]*/)?.[0]);
  docker(['exec', '-T', 'wp', 'sh', '-c', 'pkill -f "[m]ock-s3.php"; true']);
  fixture('cleanup');

  // -- Recovery (Phase 4) ------------------------------------------------------------
  console.log('\n== Emergency Recovery ==');
  await page.eval(() => (location.hash = '#/recovery'));
  await page.waitFor(() => /Recovery script/.test(document.querySelector('#wp-chrono-root').innerText) && !document.querySelector('#wp-chrono-root .animate-pulse'), [], 10000, 'recovery view');
  await page.click('Generate script', { scope: '#wp-chrono-root' }).catch(() => page.click('Regenerate', { scope: '#wp-chrono-root' }));
  await page.waitFor(() => /shown only once/.test(document.querySelector('#wp-chrono-root').innerText), [], 15000, 'password panel');
  const recovery = await page.eval(() => {
    const codes = [...document.querySelectorAll('#wp-chrono-root code')].map((c) => c.innerText.trim());
    return { url: codes.find((c) => /wp-chrono-recovery-[a-z0-9]{16}\.php$/.test(c)), password: codes.find((c) => /^[A-Za-z0-9]{24}$/.test(c)) };
  });
  check('SPA generates the recovery script and shows URL + one-time password', !!recovery.url && !!recovery.password, recovery.url);
  await page.screenshot('11-recovery-view');

  await page.goto(recovery.url);
  await page.eval((p) => (document.querySelector('input[name="password"]').value = p), recovery.password);
  const consoleLoaded = page.waitEvent('Page.loadEventFired', 30000);
  await page.click('Unlock recovery console', { scope: 'body', selector: 'button' });
  await consoleLoaded;
  const ers = await page.text('body');
  check('recovery console renders in the browser after sign-in', /Crash diagnostics/.test(ers) && /Plugins/.test(ers) && /Snapshots/.test(ers) && /connected/.test(ers));
  await page.screenshot('12-recovery-console');

  await page.goto(`${BASE}/wp-admin/admin.php?page=wp-chrono&r=${Date.now()}#/recovery`);
  await page.waitFor(() => /installed/.test(document.querySelector('#wp-chrono-root')?.innerText ?? ''), [], 15000, 'recovery status');
  await page.click('Delete', { scope: '#wp-chrono-root' });
  await page.waitFor(() => /not installed/.test(document.querySelector('#wp-chrono-root').innerText), [], 10000, 'deleted');
  const gone = await page.eval(async (u) => (await fetch(u)).status, recovery.url);
  check('SPA deletes the script from the server', gone === 404, `HTTP ${gone}`);

  // -- Error state ----------------------------------------------------------------
  console.log('\n== Error handling ==');
  const goodNonce = await page.eval(() => window.wpChrono.chronoNonce);
  await page.eval(() => (window.wpChrono.chronoNonce = 'forged'));
  await page.eval(() => (location.hash = '#/dashboard'));
  await page.waitFor(() => /session has expired/i.test(document.querySelector('#wp-chrono-root').innerText), [], 15000, 'error card');
  check('API errors render an inline error state (forged nonce → 403 message)', true);
  await page.screenshot('13-error-state');
  await page.eval((n) => (window.wpChrono.chronoNonce = n), goodNonce);

  // -- Mobile ---------------------------------------------------------------------------
  console.log('\n== Responsive ==');
  await page.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
  await page.eval(() => (location.hash = '#/snapshots'));
  await page.waitFor((l) => document.querySelector('#wp-chrono-root').innerText.includes(l), [SNAP_LABEL], 15000, 'mobile render');
  const overflow = await page.eval(() => document.querySelector('#wp-chrono-root').scrollWidth - document.querySelector('#wp-chrono-root').clientWidth);
  check('390px phone width: no horizontal overflow in the SPA', overflow <= 1, `${overflow}px`);
  const labelWidth = await page.eval(() => Math.round(document.querySelector('#wp-chrono-root [data-e2e-label]').getBoundingClientRect().width));
  check('390px phone width: snapshot labels stay readable (actions wrap below)', labelWidth >= 120, `${labelWidth}px label`);
  await page.screenshot('14-mobile-snapshots');

  const ours = page.consoleErrors.filter((e) => !/favicon|wp-emoji/i.test(e));
  check('no uncaught exceptions or console errors during the run', ours.length === 0, ours.slice(0, 3).join(' | '));
  ws.close();
}

let currentPage = null;
try {
  await run();
} catch (e) {
  check('scenario completed', false, e.message);
  if (currentPage) {
    await currentPage.screenshot('zz-failure').catch(() => {});
    const dialog = await currentPage.text('[role="alertdialog"], [role="dialog"]').catch(() => '');
    if (dialog) console.log(`--- open dialog at failure ---\n${dialog}\n---`);
    if (currentPage.consoleErrors.length) console.log(`--- console errors ---\n${currentPage.consoleErrors.join('\n').slice(0, 3000)}\n---`);
  }
} finally {
  await cleanup();
  console.log(`\n${fails ? `${fails} check(s) FAILED` : 'All checks passed'} (${passes} passed) — screenshots in ${ARTIFACTS}`);
  process.exit(fails ? 1 : 0);
}
