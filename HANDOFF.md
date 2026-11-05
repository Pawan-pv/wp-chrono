# WP Chrono — handoff (Phase 6 → v1.0.0 release)

Project root: `C:\myproject\appsumo-project\woldpressplugin`
- `wp-chrono/`  the plugin (PHP 7.4+ / WP 6.3+, React+TS SPA in `src/`, compiled to `build/`)
- `dev-env/`    offline Docker test stack + all test suites (`dev-env/tests/`), NOT part of the plugin repo
- `reference-wp-reset/`  competitor source, reference only

Approved plan: `C:\Users\pawan\.claude\plans\pasted-content-id-c595-530-530-passing-federated-peach.md`

## How to run things
- Start Docker Desktop first. Stack: `cd dev-env && docker compose up -d` → http://localhost:8088 (chrono / chrono-pass)
- Full offline suite (fresh stack, ~20 min): `bash dev-env/tests/autonomous-full-suite.sh` → matrix + logs in `dev-env/tests/artifacts/logs/`
- Build release zip: `bash wp-chrono/scripts/build-release.sh` → `wp-chrono/dist/wp-chrono-1.0.0.zip` (+ .sha256)
- Shell is Git Bash on Windows: prefix docker exec with `MSYS_NO_PATHCONV=1`; avoid `;` in curl `-F` paths.

## Phase 6 status — ALL FEATURES BUILT
1. Local `.sql.gz` import (chunked upload → validated → verified snapshot, trigger `import`) — `Services/Snapshot/LocalImport.php`, REST `/snapshots/import/{chunk,tick,cancel}`, CLI `wp chrono snapshot import`, UI `ImportSnapshotDialog`. Test: `import-checks.sh` 18/18.
2. Single-file plugin rollback cleanup (`installed_to`, `is_single_file`; stray folder removed) — `FileArchive.php`, ERS port. Test in `rollback-checks.php` 37/37.
3. Multisite network-table protection (users/usermeta/blogs/site/sitemeta/blogmeta/signups kept unless super admin opts in; `--include-network-tables`, UI toggle, ERS checkbox) — `Database::network_tables()`, `Restorer`. Test `multisite-checks.sh` 27/27.
4. Offline wordpress.org slug blueprint install (intercepted `plugins_api`/`themes_api` + `pre_http_request`) — `blueprint-checks.php` 36/36.
5. Onboarding: Welcome modal + dismissible Safety Checklist + recovery-kit .txt download — `OnboardingController.php`, `src/components/onboarding/*`.
6. Licensing: `Services/LicenseService.php` (Ed25519 offline keys `WPC1.<payload>.<sig>`, optional signed license server, Pro = Blueprints + Cloud only; restore/import/pull/ERS always free), `LicenseController`, `LicenseCommand`, Settings & License dialog (gear icon), `ProLockCard`. Production keypair generated: SECRET at `%USERPROFILE%\.wp-chrono-keys\license-ed25519.secret` (owner-only ACL, never commit), public key embedded. Mint keys: `scripts/license-sign.php`. Test `license-checks.sh` 35/35.
7. v1.0.0: version bumped, `readme.txt`, `languages/wp-chrono.pot`, ERS template renamed `includes/Recovery/ers-template.php.txt`, `dev-env/tests/plugin-audit.php` (16 checks, negative-control verified), `scripts/build-release.sh` + `make-zip.php`, `release-checks.sh` 33/33 (installs zip on fresh WP, audit, uninstall).

Bugs found & fixed this phase (real, pre-existing): `.sql.gz` download always 403 (`sanitize_file_name` turns `.sql.gz` into `.sql_.gz`); download_url HTML-escaped `&amp;` broke SPA links; `activate()` double-called license server.

## Status (2026-10-26) — release candidate, all green
Full fresh-stack suite: ALL SUITES PASSED, 666 checks, 0 failures.

Intermittent "database back to v1" failure = REAL race, now fixed: a request running the updated plugin code
between the table swap and the folder swap wrote its data back into the restored DB. Fix: WordPress maintenance
mode (`.maintenance`) for the swap window — `FileArchive::maintenance_on/off()` used by `Restorer::swap()` (full
mode only), `wpc_maintenance_on/off()` around the whole ERS undo. A pre-existing `.maintenance` is never touched.
Proof: under continuous HTTP traffic 3/3 runs green with the fix; negative control (fix stubbed out) failed 3 of 6
DB checks with SQL value 'v2'. `update-rollback-checks.sh` now also asserts `.maintenance` is removed (29 checks).

## Session 2026-10-26 (evening) — WP 7.1.3, nginx/FPM stack, Plugin Check
New test infrastructure (dev-env, not in the plugin repo):
- `WP_TARBALL=wordpress-7.1.3.tar.gz` selects the WordPress release (cache/wordpress-7.1.3.tar.gz, SHA-1 verified).
- `COMPOSE_FILE=prod/docker-compose.yml` = nginx + PHP-FPM + OPcache, PHP as user nginx, 1 MB body limit,
  256M / 30 s, pretty permalinks (image wp-chrono-prod:latest). Same port 8088: stop one stack before the other.
- cache/plugin-check.zip (Plugin Check 2.1.0) → release-checks.sh runs it and fails on any ERROR.

Real bugs found and fixed (uncommitted, in wp-chrono/):
1. WP 7.x admin command palette also opens on Ctrl/Cmd+K (also cmdk) → both palettes opened. Fix: window capture listener + stopImmediatePropagation (CommandPalette.tsx). UI check added.
2. Import upload failed on hosts whose web-server body limit < PHP upload limit (nginx default 1 MB → HTTP 413). Fix: halve chunk on 413 and resend (ImportSnapshotDialog.tsx); clear 413 message in apiUpload. UI fixture now >1 MiB.
3. Plugin Check: 38 errors → 0 (identifiers via $wpdb->prepare %i; correct phpcs ignore codes + reasons for atomic rename(), dump escaping, wp-content storage, S3 endpoint template). 64 warnings remain (see below).
4. Rejected import files (corrupt / truncated / DROP TABLE / foreign prefix) returned HTTP 500 → now 422.
5. readme: nginx deny snippet (must be QUOTED and ABOVE the .php location — both verified on nginx), short description ≤150, Tested up to 7.1.
Test-only fix: cloud-checks.sh runs the mock S3 as the WordPress file owner.

Results: WP 7.1.3 dev stack all green except items above (fixed after); prod stack all green after fixes
(cloud 32/32 rerun); release-checks 34/34 incl. Plugin Check 0 errors.
FINAL run A (prod stack, WP 7.1.3, all fixes): ALL SUITES PASSED — 23/23 suites, 669 checks, 0 failures.
STILL TO DO (run B was never started — host memory pressure):
  B) (stop prod stack) bash dev-env/tests/autonomous-full-suite.sh            # dev stack, WP 6.7.2
  then commit + move tag v1.0.0 (never published, no remote) + rebuild dist zip.

## Cloudflare backend (2026-10-26) — DEPLOYED to account "wordpress-plugin"
`cloud/` = Worker `wp-chrono-api` (cf CLI 1.0.0-beta.22 as devDependency; `cloudflare.config.ts`, no Wrangler file).
See `cloud/README.md` for the runbook.
- License server `POST /v1/license` (existing WPCR1 contract; seats = prod sites seen in 45 days, dev/staging hosts free,
  statuses active|site_limit|revoked|expired|inactive), D1 `wp-chrono-licenses` (licenses, activations, events), rate limit, daily prune cron.
- Updates `GET /v1/update/wp-chrono` + `GET /v1/download/wp-chrono/<zip>` from R2 `wp-chrono-releases`.
- Separate OFFLINE release key `~/.wp-chrono-keys/release-ed25519.secret` (pub `HH03nclV…` in `Updater::RELEASE_KEYS`);
  plugin verifies sha256 + Ed25519 before installing. Back it up offline together with the license key.
- Plugin: `Services/Updater.php` (Update URI filter, View details, verified download), LicenseService default server =
  `{Updater::api()}/v1/license` (WP_CHRONO_LICENSE_SERVER '' = offline), `site_limit` message, readme Privacy section.
- LIVE: https://wp-chrono-api.wordpress-plugin.workers.dev (Worker wp-chrono-api, D1 wp-chrono-licenses 406f53e6-…, R2 wp-chrono-releases, secret LICENSE_SIGNING_KEY = secret_text). Update URI points there; signed 1.0.0 published and verified (sha256 + release signature).
- Tests: `cloud && npm test` 52/52 (needs `npm run dev`); `dev-env/tests/update-checks.sh` 17/17 (mock, in full suite);
  `update-checks.sh --worker` 16/16 (Docker WP ↔ local Worker). Plugin Check's `plugin_updater_detected` is expected
  (a wordpress.org build must drop Updater.php); release-checks ignores only that code.
- Moving to an own domain later: add the zone, `API_DOMAIN=api.<domain> node scripts/deploy.mjs`, change Update URI, publish a new version (workers.dev keeps working for old installs).

## Safe Updates (2026-10-27) — built + tested, committed on branch `cloudflare-backend` (not released)
- `Services/SafeUpdates.php`: scrape-probe baseline before each update batch, re-probe after
  (`upgrader_process_complete`; background runs at `automatic_updates_complete`, core 6.6 rollback codes count as broken),
  Full Undo on regression, notice + email + updater/WP-CLI message, blocks that version from auto-updates.
  Never acts on "unverified" (no baseline / no answer). Setting `safe_updates` (default on).
- Found + fixed a real bug in every Full Undo: with OPcache, PHP-FPM ran the reverted (broken) code for up to
  revalidate_freq s after the folder swap when the undo ran from CLI → wrote into the restored DB. Restore/ERS now
  hold maintenance mode revalidate_freq+1 s when opcache_reset() can't reach the web cache (`wp_chrono_opcache_settle`).
- Tests: `dev-env/tests/safe-update-checks.php` 31/31 (in full suite). Full suite on a fresh prod-like stack:
  ALL 25 SUITES PASSED, 721 checks. Probe URL filter `wp_chrono_safe_updates_probe_url` (dev stack loopback = port 80 + Host header).
- Not yet: core (WordPress) updates aren't snapshotted/checked; no scheduled snapshots; Pro extras (custom URLs, Slack).

## 1.0.1 RELEASED (2026-10-27)
master fast-forwarded + tag v1.0.1; signed zip published to the live API and verified (update offered to 1.0.0, sha256 +
release signature OK). `.gitattributes` (LF) added on master afterwards — global core.autocrlf=true had turned checked-out
files into CRLF.

## Next (branch `core-and-scheduled`, readme changelog "1.0.2", version still 1.0.1)
- WordPress core updates: snapshot on `upgrader_pre_download` (Core_Upgrader), `core` archive entries (wp-admin, wp-includes,
  root files; never wp-config/wp-content), new version on `_core_updated_successfully`, Safe Updates check there (wp-admin
  exits before upgrader_process_complete), `auto_update_core` block, ERS + UI support. Test `core-update-checks.php`
  (real 6.7.2 → 7.1.3 update from dev-env/cache, undo, broken-plugin scenario).
- Fix: restored snapshots brought back `core_updater.lock` / `auto_updater.lock` (15-min update block) → cleared after restore.
- Scheduled snapshots: `Services/Scheduler.php` (daily / weekly Sundays at an hour, site timezone; sliced build via
  `wp_chrono_scheduled_snapshot_continue`; own trigger `scheduled` + retention `schedule_keep`; self-heals the cron event;
  action `wp_chrono_scheduled_snapshot_ready` for future cloud push). Test `scheduled-checks.php`.
- Tooling gotcha: Git Bash heredocs here eat backslashes — edit PHP/TS with the editor, not heredoc scripts.

## Open before deploy
- Commit the fix in `wp-chrono/` (tag v1.0.0 currently points at the pre-fix commit 71c1add; move the tag or tag 1.0.1).
- dist zip was rebuilt with the fix (sha256 in `dist/wp-chrono-1.0.0.zip.sha256`).
- Known limits: SPA English-only; official Plugin Check not run (offline); Tested up to 6.7; readme lacks
  `Contributors:`; cloud tested only against the mock S3.

## 1.0.3 — pre-launch audit fixes (2026-10-30, branch `core-and-scheduled`, not yet committed/released)
Root cause of the flaky Safe Updates suite: an abandoned restore job (`wp_chrono_restore_job`) blocked every restore —
incl. automatic undos — for an hour; plus PHP-FPM OPcache serving a stale `.maintenance` / stale plugin files after a
CLI files-only undo when WordPress's own `.maintenance` existed (settle was skipped).
- Restorer: STALE_AFTER 10 min, long ticks touch the job every 30 s, Safe Updates takes over jobs idle > 2 min.
- FileArchive::maintenance_off settles OPcache even when the `.maintenance` wasn't ours; undo_files uses the same window.
- SafeUpdates: failure must repeat on 2 retries (filter `wp_chrono_safe_updates_retries` / `_retry_delay`), 503 never
  counts, stale-.maintenance 503 re-probed after revalidate_freq+1. DB rollback only when no new posts/comments/users/
  wc_orders since the snapshot (`new_records()`, filter `wp_chrono_safe_updates_watch_tables`, `_undo_mode`), else files-only.
- Database::foreign_prefixes(): other WP installs in the same DB (wp_shop_ next to wp_) are never snapshotted/reset/
  restored/uninstalled. uninstall.php drops only this install's `_wpc_` tables; setting `keep_data_on_uninstall`.
- CloudSync: manifest `rollback_mac` (HMAC from wp-config salts); pulls only restore code archives this site signed;
  FileArchive::localize_entry() rebuilds targets, drops installed_to.
- Importer: strict statement shapes (mask_literals / refuse_create / is_plain_insert / is_safe_fk).
- DiffService: no object unserialize. Blueprint URLs https only. Rollback zip names carry a random token.
- Cloud worker: seat rules tightened (sites.ts, test/sites-checks.mjs), license body capped incl. chunked,
  worker-checks uses the newest dist zip.
New tests: import-validation-checks.php, tamper cases in cloud-checks.php, visitor-data / 502 / 503 in
safe-update-checks.php, keep-data + neighbour-install uninstall in release-checks.sh.
STILL USER-SIDE (open items):
1. Merge ci-github-actions → main & rebuild/re-tag v1.0.3 release zip.
2. Custom API domain cutover.
3. Real-bucket R2/S3 test.
4. SPA i18n translation.
5. Offline backup of ~/.wp-chrono-keys/*.secret.

## CI & standardization (2026-11-05, branch ci-github-actions)
- v1.0.3 audit fixes (9660680) + post-1.0.3 runtime hardening (Importer.php comment masking,
  SnapshotController 413 handling, pricing/UI) committed and passing in standard CI.
- CI/test infra committed: PHPStan level 5 (phpstan.neon.dist, composer require-dev), Vitest for src/
  (vitest.config.ts + unit tests), Playwright E2E (playwright.config.ts, e2e/smoke.spec.ts),
  phpunit suite (tests/phpunit/*), and .github/workflows/standard.yml.
- standard.yml is green on GitHub Actions: PHPStan (level 5) ✓ and Vitest (src/) ✓.
- full.yml (Docker integration) ran on PR #2: updates ✓ and data ✓ pass; ui-release initially failed on
  the "Settings & License" ui-e2e check (dialog closed before save completed). Fixed by:
  1. api() now throws on null/empty responses instead of silently returning null.
  2. Settings & License E2E retries the save if the Radix Dialog closes, and clicks the Save
     button via element reference instead of screen coordinates.
  3. CI re-run confirmed Settings & License: all 6 checks PASS (300 MB saved, license activation,
     Pro unlock all green).
- Blueprints "Reset to Dev Baseline" ui-e2e check then failed with "Cannot read properties of null
  (reading 'ok')" — the apply_step API returned null after reset/full wiped and reinstalled WordPress.
  Fixed by:
  1. New GET /wp-chrono/v1/nonce endpoint (authorized by manage_options + X-WP-Nonce only, not
     X-WP-Chrono-Nonce) so the SPA can get fresh credentials after reset.
  2. refreshConfig() in plans.ts fetches fresh nonces after reset/full.
  3. api() throws a clear error on empty responses.
  Fix committed on ci-github-actions but NOT yet verified in CI: setup-stack step is failing across
  ALL workflows (quick.yml too) due to a GitHub Actions infrastructure issue (Docker/cache), not
  related to code changes.
- quick.yml green (core suites) on push.

CI infrastructure note: setup-stack step is failing on GitHub Actions (Docker image load / cache),
affecting all workflows. This is unrelated to code changes. Retry CI when the runner recovers.

Do NOT touch main or the v1.0.3 tag until CI is fully green and explicitly confirmed.
