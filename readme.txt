=== WP Chrono — Reset, Snapshots, Update Undo & Emergency Recovery ===
Tags: reset, snapshot, rollback, backup, recovery
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
Contributors: [WP.ORG USERNAME]
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Undo plugin updates (files + database), reset safely, and recover a crashed site even when WordPress won't load. Restoring is always free.

== Description ==

WP Chrono is a time machine for your WordPress database and the plugins, themes and core files you update. It is not a full-site backup (media uploads are not included), so keep your backup plugin. It takes a snapshot before every update, lets you undo the update — code *and* data — in one click, and gives you an Emergency Recovery Script that still works when a fatal error has taken the whole site down.

**Restoring is always free.** Snapshots, every kind of restore, importing your own backups and the recovery script are never behind a paywall.

= Undo plugin, theme & WordPress updates (files + database) =

* Before every update batch, WP Chrono snapshots the database **and** zips the plugin / theme folders that are about to be replaced — one snapshot per batch, even when you update ten plugins at once.
* **WordPress core updates too**: `wp-admin`, `wp-includes` and the core files in your site root are archived before the update (never `wp-config.php` or `wp-content`), so a major WordPress release can be undone — also from the Emergency Recovery Script when the update took the site down.
* **Full Undo** puts back the old files and the old database together, so the old plugin never runs against a migrated database. Or restore *Database Only* / *Files Only*.
* Archives are SHA-256 verified; folders are swapped with atomic renames, so a failed restore never leaves a half-written plugin behind.

= Safe Updates: broken updates undo themselves =

* After every update — from wp-admin, WP-CLI or WordPress's background auto-updates — WP Chrono loads your home page (and wp-admin) the way WordPress itself checks for fatal errors.
* If the update broke a page that worked before, it is **undone automatically: files and database**, from the snapshot taken seconds earlier. You get an email and an admin notice naming the plugin and the error.
* That exact version is then never auto-updated again (a newer one is). WordPress itself only puts back the old plugin files, and only when the install fails or an auto-update causes a fatal error — it never touches the database.
* It never guesses: if your host blocks the check, nothing is undone and the snapshot says "Update not checked". Free, on by default; turn it off under Settings.

= Snapshots with atomic restore =

* Chunked snapshot creation that survives shared-hosting time limits (tested with 240,000+ rows).
* **Atomic restore**: tables are staged, then swapped in with a single `RENAME TABLE`. Your site is never half-restored, and you stay logged in.
* Every snapshot is **verified** (row and column counts) the moment it finishes.
* **Scheduled snapshots**: daily or weekly at the hour you pick, kept separately from the update snapshots.
* Visual diff of tables and `wp_options` against the live site.
* Export to `.sql.gz` and **import it back** — even onto a freshly reset site. No phpMyAdmin, no paywall.
* Foreign keys are captured and re-created; multisite main-site restores never touch subsite tables or network users.

= Emergency Recovery Script =

A single standalone PHP file with a secret URL and password that **never loads WordPress**. When a plugin update white-screens your site, open it and:

* see the fatal error and the plugin or theme that caused it,
* **Undo Last Update (Files + DB)**, deactivate the crashing plugin or switch to a default theme,
* restore any snapshot atomically (even if WP Chrono's own tables are damaged),
* create an emergency administrator or repair `siteurl` / `home`.

Protected with bcrypt, signed session cookies, CSRF tokens and brute-force lockout.

= Safe resets =

* Full reset that keeps your admin account (same ID, password and session), site title and URLs.
* Partial cleaners with dry runs: transients, uploads, inactive plugins and themes, custom tables, orphaned meta, options, WooCommerce data.
* A safety snapshot is taken before every reset.
* The uploads cleaner **never touches backup folders** from UpdraftPlus, All-in-One WP Migration, WPvivid, Duplicator, BackWPup, BlogVault and others.

= Pro: Blueprints & Cloud =

* **Blueprints** — capture a site's plugins, theme and baseline settings (optionally with local zips, for premium plugins and offline use), share them as JSON, and **Reset to Dev Baseline** in one click.
* **Cloud offload** — push snapshots and update archives to your own Cloudflare R2, Amazon S3 or Backblaze B2 bucket (AWS Signature v4, no SDK, secret key encrypted at rest). Beta: tested against an S3-compatible test server; please report any provider that misbehaves.

Pro keys are verified offline, so no account is needed. By default the site checks in once a day to count the sites a key is used on (see Privacy); define `WP_CHRONO_LICENSE_SERVER` as `''` to switch that off.

= For developers =

Every feature is available from WP-CLI: `wp chrono status`, `wp chrono reset …`, `wp chrono snapshot create|list|diff|restore|export|import|verify|scavenge`, `wp chrono recovery …`, `wp chrono blueprint …`, `wp chrono cloud …`, `wp chrono license …`.

= Privacy =

WP Chrono does not track you or load anything on your public pages. It makes these outbound requests, only from wp-admin, WP-Cron or WP-CLI:

* **Update check** (the WP Chrono API): sends only the installed WP Chrono version, at most twice a day, so WordPress can offer updates. Every update package is verified against an Ed25519 release signature before it is installed.
* **License check**, only when a license key is entered: sends the key, your site address and the plugin version on activation, deactivation and at most once a day, to count the sites a key is used on. Define `WP_CHRONO_LICENSE_SERVER` as `''` in `wp-config.php` to keep licensing fully offline.
* **Your cloud bucket** (Pro), only if you configure one.

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload Plugin, or install it from the Plugins screen.
2. Activate it and open **WP Chrono** in the admin menu.
3. Follow the three-step safety checklist: generate and download your recovery kit, keep auto-snapshots on, and take a baseline snapshot.

== Frequently Asked Questions ==

= Is this a backup plugin? =

Snapshots live in your own database (and, with Pro, your own bucket). They are ideal for undoing changes and updates. For disaster recovery of a production site, also keep off-site backups: export a snapshot to `.sql.gz` or push it to the cloud.

= Can I restore a .sql.gz on a site I just reset? =

Yes. Snapshots → Import .sql.gz uploads the file in chunks (upload size limits don't matter), checks that it is a genuine WP Chrono export for this site, loads it into a new verified snapshot — never straight into your live tables — and lets you restore it atomically. This is free.

= What happens if an update crashes my whole site? =

Open your Emergency Recovery Script (download the recovery kit during onboarding so you have the URL and password). It works without WordPress and offers "Undo Last Update (Files + DB)".

During any undo that swaps both files and database, visitors see WordPress's standard "Briefly unavailable for scheduled maintenance" page for a moment, so no request can run the broken code against the restored database.

= I use nginx. Are my snapshot files safe? =

Yes. Nginx ignores `.htaccess`, so WP Chrono stores everything in a `wp-content/wp-chrono-<random>` folder and gives every database export a random 32-character file name; downloads always go through a logged-in, nonce-checked request. For defense in depth, add this to your server block, above the `location ~ \.php$` block (nginx checks regex locations in order):

`location ~* "^/wp-content/wp-chrono-[A-Za-z0-9]{16}/" { deny all; }`

= What exactly does Safe Updates undo, and what does it keep? =

The plugin / theme (or WordPress) files the update replaced, always. The database goes back to the snapshot taken just before the update only when nothing new arrived in the meantime: if a visitor placed an order, left a comment or registered (new posts, comments, users or WooCommerce orders since the snapshot), only the files are put back, so their data is never lost. The admin notice and email then say so, and you can still run a Full Undo yourself from the snapshot.

= Which pages does Safe Updates check? =

The home page, wp-admin (when you update from the dashboard), and — when WooCommerce is active — the **cart, checkout and My Account** pages. Shop pages must still show their WooCommerce content (for example the checkout form), so an update that leaves checkout empty is caught even without a PHP error. Add more pages of your site under Settings → "Also check these pages after updates", or with the `wp_chrono_safe_updates_probe_targets` filter.

The checks are anonymous visits with an empty cart: they never create carts, sessions or orders.

A page only counts as broken when the failure repeats on three checks a few seconds apart; HTTP 503 (maintenance mode, overload), timeouts and slow pages never count.

= Does a page cache or CDN fool the check? =

No. Every check request carries a unique one-time query parameter plus `Cache-Control: no-cache, no-store` and `Pragma: no-cache`, and WordPress must print a matching one-time marker into the page it renders for that request. A copy served from any cache lacks that marker, so it is never counted as "working" — that page is reported as "Not checked" instead.

Caches that ignore unknown query parameters (some CDN / host cache-key rules, nginx `fastcgi_cache` keyed on `$uri`, "ignore query string" settings) and caches that answer before WordPress starts (`advanced-cache.php` page caches, edge caches) cannot be bypassed: on such sites the affected pages are skipped, never undone by mistake. To have them checked, exclude requests carrying `wp_scrape_key` from caching.

= What if my host blocks "loopback" requests? =

Then WP Chrono can't load your pages, so nothing is ever undone automatically (updates are still snapshotted and can be undone by hand). WP Chrono shows an admin notice saying so; ask your host to allow loopback requests (Tools → Site Health reports the same problem).

= What happens to my snapshots if I delete WP Chrono? =

By default deleting the plugin removes everything it created: snapshots, exports and settings. To keep them for a reinstall, turn on Settings → "Keep snapshots if WP Chrono is deleted". The Emergency Recovery Script is always removed. Other WordPress installs that share the same database are never touched.

= Does WP Chrono slow down my site? =

No. Nothing runs, loads or queries on public page views. All work happens in wp-admin, the REST API, WP-CLI or WP-Cron.

= Does it work on multisite? =

Snapshots and restores are scoped to the current site: the main site never touches `wp_2_*` tables, and network-wide tables (users, blogs) are kept unless a super admin explicitly includes them. Full and partial resets are disabled on multisite for safety.

= What is free and what is Pro? =

Free forever: resets, snapshots, auto-snapshots, every restore mode including Full Undo, `.sql.gz` import, pulling backups from your cloud bucket, and the Emergency Recovery Script. Pro: Blueprints and Cloud offload (push).

= How do I activate a Pro / lifetime-deal key? =

Settings & License (the gear icon) → License, paste the key and click Activate. Agencies can also define `WP_CHRONO_LICENSE_KEY` in `wp-config.php`, or run `wp chrono license activate <key>`.

== Screenshots ==

1. Overview with the first-run safety checklist.
2. Snapshots timeline with verified badges and "Undo update".
3. Full Undo: restore database + revert updated plugin files.
4. Emergency Recovery Script working while WordPress is down.
5. Blueprints & Cloud.

== Changelog ==

= 1.0.4 =
* New: storage cap for snapshots — a GB cap plus a minimum autos to keep when over the cap. Oldest auto-snapshots are deleted first; manual, pinned and the latest update snapshot are never auto-deleted.
* Fix: auto_retention is now always respected when under the GB cap, and the GB cap never deletes below the minimum autos setting.
* Fix: pinned snapshots and Safe Updates undo snapshots are protected during retention cleanup.
* Fix: disk-space check skips a pre-update snapshot instead of filling the disk.

= 1.0.3 =
* Safety: Safe Updates no longer rolls the database back when visitors created new orders, comments, users or posts after the update started; it puts the files back and tells you. A failure must now repeat on three checks before anything is undone, and HTTP 503 (maintenance) never counts.
* Safety: on databases shared by several WordPress installs (one table prefix each), resets, snapshots, restores and uninstall no longer touch another install's tables (for example `wp_shop_*` next to `wp_*`).
* Security: pulling a backup from cloud storage only restores plugin / theme / core archives this site signed itself, with file paths rebuilt locally; a tampered bucket can no longer place code on the site.
* Security: imported `.sql.gz` files are checked statement by statement and may only contain plain table definitions and value lists (no `SELECT`, functions or remote storage engines).
* Security: snapshot diffs never unserialize objects; blueprint package URLs must use HTTPS; rollback archives get unguessable names.
* Security (multisite): only super admins can create the Emergency Recovery Script, file restores and blueprints require the core install/update capabilities, and both follow `DISALLOW_FILE_MODS`.
* Fix: a restore abandoned half-way (browser closed) blocked every restore, including automatic undos, for an hour. Idle jobs now expire after 10 minutes and Safe Updates takes them over.
* Fix: after an undo run from WP-CLI or WP-Cron, PHP-FPM could keep serving the replaced plugin for a few seconds when WordPress's own maintenance file was in place.
* New: Settings → "Keep snapshots if WP Chrono is deleted". Uninstall cleans every site of a network.

= 1.0.2 =
* New: **scheduled snapshots** — daily, or weekly on Sundays, at an hour you choose (site time zone), with their own retention. Built in small steps through WP-Cron, so they finish on hosts with short time limits.
* New: WordPress core updates are snapshotted and archived (wp-admin, wp-includes, root files) and can be undone with Full Undo or from the Emergency Recovery Script. Safe Updates checks core updates too and undoes one that breaks the site.
* Fix: restoring a snapshot taken during an update brought back WordPress's update lock, blocking updates for 15 minutes.

= 1.0.1 =
* New: **Safe Updates**. After every update WP Chrono checks that the site still loads; if the update broke it, the update is undone automatically (files + database), you get an email and an admin notice, and that version is not auto-installed again.
* New: updates for WP Chrono itself, verified with a release signature before they are installed.
* New: license keys can be checked against the WP Chrono license server (site limits, refunds); a key keeps working if the server can't be reached. Define `WP_CHRONO_LICENSE_SERVER` as `''` to stay offline.
* Fix: during an undo, a request could run the updated plugin against the restored database and write its data back. The site now stays in maintenance mode for the whole swap, and until PHP's OPcache has dropped the replaced code.
* Fix: a corrupt or truncated `.sql.gz` import is reported as a file problem (422) instead of a server error.

= 1.0.0 =
* First public release.
* Full update rollback: plugin / theme files + database, from wp-admin, WP-CLI or the Emergency Recovery Script.
* Snapshots: chunked creation, verification, diff, atomic restore, `.sql.gz` export and import.
* Emergency Recovery Script with crash diagnostics, plugin / theme switchboard and snapshot rollback.
* Safe full and partial resets with dry runs and protected backup folders.
* Multisite-aware table scoping and network-table protection.
* First-run safety checklist and recovery kit download.
* Pro: Blueprints (Reset to Dev Baseline) and bring-your-own-bucket cloud offload; offline Ed25519 license keys.

== Upgrade Notice ==

= 1.0.4 =
Storage cap: the GB cap only trims oldest auto-snapshots and never below the minimum autos setting. Recommended for sites with many snapshots.

= 1.0.3 =
Security and data-safety release: Safe Updates never deletes new orders or comments, safer cloud pulls and imports, shared-database fixes. Recommended for everyone.

= 1.0.2 =
Scheduled snapshots, and WordPress core updates can now be undone.

= 1.0.1 =
Adds Safe Updates (broken updates undo themselves) and fixes a rare race during undo. Recommended.

= 1.0.0 =
First public release.
