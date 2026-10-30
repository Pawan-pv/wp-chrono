# WP Chrono

Reset, snapshots, full update undo (files + database), and an Emergency Recovery Script for
WordPress. Restoring is always free. Version 1.0.0 — see `readme.txt` for the user-facing
description.

## Development

```bash
npm install
npm run build      # type-check + production bundle -> build/index.js, build/index.css
npm run dev        # rebuild on change (development mode, with sourcemaps)
composer install   # optional; the plugin ships a fallback PSR-4 autoloader
```

Copy or symlink this folder into `wp-content/plugins/wp-chrono`, activate it, and open **WP Chrono** in the admin menu.
The offline Docker test stack and the full test suite live in `../dev-env` (outside this repository).

## Release

```bash
bash scripts/build-release.sh   # npm build, PHP 8.3 + 7.4 lint, dist/wp-chrono-<version>.zip + .sha256
```

The zip contains only runtime files: `wp-chrono.php`, `uninstall.php`, `readme.txt`, `includes/`,
`build/`, `languages/`. Regenerate `languages/wp-chrono.pot` with
`wp i18n make-pot . languages/wp-chrono.pot --exclude=node_modules,src,build,scripts,dist,vendor`.

## Licensing (vendor side)

Pro features (Blueprints, Cloud offload) are unlocked by Ed25519-signed keys verified offline against
`LicenseService::PUBLIC_KEYS`. The signing key never lives in this repository:

```bash
php scripts/license-keygen.php      # once: writes ~/.wp-chrono-keys/license-ed25519.secret, prints the public key
php scripts/license-sign.php --plan=pro --sites=1 --source=appsumo --count=500 > codes.csv
```

Back the secret key up offline; losing it means you can no longer issue keys for the embedded public key.
The optional license-server contract (activation / revocation, signed verdicts) is documented in
`includes/Services/LicenseService.php`.

## CI (GitHub Actions)

The suites in `dev-env/` (a copy of `../dev-env`; refresh with `bash scripts/sync-dev-env.sh`)
run on GitHub's Linux runners on an offline stack, as locally.

| Workflow | When | What |
|---|---|---|
| `quick.yml` | every push to a branch (not `*.md`-only changes) | one job, prod stack: tsc + build, PHP 8.3/7.4 lint, Safe Updates, Rollback, WooCommerce, undo over REST + ERS, release package incl. Plugin Check |
| `full.yml` | PRs to `master`/`main`, nightly 02:17 UTC (skipped if nothing was committed in 24 h), manual | all suites in 3 parallel groups (`updates`, `data`, `ui-release`) on the prod stack |
| `release.yml` | pushing a `v*` tag | tag = plugin version = Stable tag, then the full suite on **both** stacks; the `release-gate` check is red if anything failed. Do not publish a tag with a red gate. CI never builds, signs or publishes. |

Manual full run: GitHub → **Actions → full → Run workflow** (choose `prod`, `dev` or `both`), or
`gh workflow run full.yml --ref <branch> -f stacks=both`.

Failed jobs upload `dev-env/tests/artifacts/` plus container logs (7-day retention). CI generates a
throwaway Ed25519 key pair per job and fails if a production public key (`LicenseService::PUBLIC_KEYS`,
`Updater::RELEASE_KEYS`) is used by the tests; `~/.wp-chrono-keys` is never read.
`.github/ci/forbidden-files.sh` fails CI if `reference-wp-reset/`, keys or secrets are tracked; enable the
same check before each commit with `git config core.hooksPath .githooks`.

## Layout

```
wp-chrono.php            bootstrap, constants, autoloader
uninstall.php            drops all WP Chrono tables, _wpc_ snapshot tables, options and files
includes/Core/           Plugin (hook wiring), Admin (menu + assets), Installer, Schema, Storage
includes/API/            RestController base (capability + nonce gate, Pro gate), Router, controllers
includes/Services/       business logic: ResetService (+ Reset/ cleaners), SnapshotService (+ Snapshot/
                         TableCopier, Restorer, Exporter, Importer, LocalImport, Scavenger, FileArchive,
                         CloudSync), BlueprintService, CloudStorageService (S3 SigV4), LicenseService,
                         RecoveryGenerator, SiteInspector
includes/Recovery/       ers-template.php.txt — the standalone Emergency Recovery Script (never loads
                         WordPress; armed copies are written to the site root, the template never runs)
includes/CLI/            `wp chrono status|reset|snapshot|recovery|blueprint|cloud|license …`
languages/               wp-chrono.pot
scripts/                 release + licensing tools (not shipped)
src/                     React 18 + TypeScript + Tailwind + shadcn/ui SPA
build/                   compiled assets (enqueued only on the WP Chrono admin screen)
```

## Conventions

- **REST:** every route lives under `/wp-json/wp-chrono/v1/` and is registered through
  `RestController::route()`, which forces `manage_options` plus a valid `X-WP-Chrono-Nonce`.
  Pro-only actions call `require_pro()`; restore, import and recovery endpoints never do.
- **Snapshot tables:** `_wpc_{id}_{hash8}_{source_table}` (≤ 64 chars) with a JSON COMMENT
  naming the original, each recorded in `{prefix}wpchrono_snapshot_tables`. The tag is outside
  `$wpdb->prefix` so it never collides with live tables. On multisite every table query is scoped
  with `Database::belongs_to_site()` (the main site never touches `wp_2_*`), and main-site restores
  keep `Database::network_tables()` unless a super admin includes them.
- **Sealed storage** (`wp-content/wp-chrono-{random}/`): `.sql.gz` exports and imports,
  `rollbacks/` (plugin / theme zips taken before updates) and `packages/` (blueprint zips).
- **Front end:** no hooks run on public pages. Tailwind is scoped to `#wp-chrono-root`
  with preflight disabled, so wp-admin chrome is untouched.
