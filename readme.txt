=== WP Chrono — Reset, Snapshots, Update Undo & Emergency Recovery ===
Tags: reset, snapshot, rollback, backup, recovery
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Undo any plugin update (files + database), reset safely, and recover a crashed site from a secret page that works even when WordPress doesn't. Restoring is always free.

== Description ==

WP Chrono is a time machine for your WordPress database and the plugins you update. It takes a snapshot before every update, lets you undo the update — code *and* data — in one click, and gives you an Emergency Recovery Script that still works when a fatal error has taken the whole site down.

**Restoring is always free.** Snapshots, every kind of restore, importing your own backups and the recovery script are never behind a paywall.

= Undo plugin & theme updates (files + database) =

* Before every update batch, WP Chrono snapshots the database **and** zips the plugin / theme folders that are about to be replaced — one snapshot per batch, even when you update ten plugins at once.
* **Full Undo** puts back the old files and the old database together, so the old plugin never runs against a migrated database. Or restore *Database Only* / *Files Only*.
* Archives are SHA-256 verified; folders are swapped with atomic renames, so a failed restore never leaves a half-written plugin behind.

= Snapshots with atomic restore =

* Chunked snapshot creation that survives shared-hosting time limits (tested with 240,000+ rows).
* **Atomic restore**: tables are staged, then swapped in with a single `RENAME TABLE`. Your site is never half-restored, and you stay logged in.
* Every snapshot is **verified** (row and column counts) the moment it finishes.
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
* **Cloud offload** — push snapshots and update archives to your own Cloudflare R2, Amazon S3 or Backblaze B2 bucket (AWS Signature v4, no SDK, secret key encrypted at rest).

Pro keys are verified offline — no account and no license server needed.

= For developers =

Every feature is available from WP-CLI: `wp chrono status`, `wp chrono reset …`, `wp chrono snapshot create|list|diff|restore|export|import|verify|scavenge`, `wp chrono recovery …`, `wp chrono blueprint …`, `wp chrono cloud …`, `wp chrono license …`.

= Privacy =

WP Chrono does not track you, phone home or load anything on your public pages. It only makes outbound requests you configure: to your own cloud bucket (Pro) and, if you set one, a license server.

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

= 1.0.0 =
First public release.
