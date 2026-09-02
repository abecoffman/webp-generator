=== WebP Generator ===
Contributors: abecoffman
Tags: webp, image optimization, performance, media, images
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.15.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates WebP (and AVIF, if your server supports it) versions of your images automatically, catches up your existing media library on demand, and helps your server actually serve them.

== Description ==

WebP Generator creates a `.webp` version of every image you upload, automatically, for the original file and every size WordPress generates, using Imagick or GD (no shell access or external binary required). If your server's Imagick or GD build also supports AVIF (roughly half the file size of WebP at comparable quality), an `.avif` version is generated right alongside it automatically too, the moment your server can do it -- on by default, with nothing to configure, but either format can be turned off from Settings if you'd rather not generate it. New uploads are covered from the moment you activate the plugin.

For everything uploaded *before* that, **Tools → WebP Generator** adds a simple Generate / Cancel tool:

* **Generate** first checks how many images in your media library are missing a converted version, and how much that adds up to, before writing anything — the result sticks around ("Library status") even after conversion starts, so you can always see where things stand. Reload the page, come back tomorrow, it's still there, dated, and re-checking is automatic the next time you click Generate.
* Once that's done, it converts them in the background — it keeps running even if you close the browser tab, so a large library doesn't need to be babysat. Come back any time and the page picks up exactly where things stand: still checking, still running, paused, or finished, no need to click Generate again to see it. If you're elsewhere in wp-admin when it finishes, a notice and a Tools-menu badge let you know. It's resumable: batches are small and bounded so it can't time out on a large library, and if you click **Cancel**, it pauses exactly where it was — click Generate again any time to pick up from that point.

= Serving the files =

Generating these files is only half the job — browsers only get them if your server is configured to serve the best one it accepts instead of the original. WebP Generator can set this up for you with one click on Apache and LiteSpeed (both read the same `.htaccess`/mod_rewrite syntax), AVIF prioritized ahead of WebP whenever both exist for a file. It's reversible any time, and only ever touches one clearly-marked block of your `.htaccess`, the same mechanism WordPress core uses for its own rewrite rules. If your server later gains AVIF support after the rule was already installed, the plugin notices and offers to update it. On Nginx or IIS, the plugin links you straight to the official documentation for configuring the equivalent rule.

= Plays well with page caching =

If your site runs Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, or LiteSpeed Cache, newly-generated files automatically trigger a cache clear — otherwise a page cached before the file existed could keep serving the old format until that cache entry expired on its own.

= Also included =

* Each format can be turned on or off, and its quality set independently (1-100, default 80 for WebP, 85 for AVIF), from Settings -- WebP and AVIF aren't on a perceptually equivalent scale at the same number, so each gets its own slider, with per-use-case guidance (backgrounds, thumbnails, photography) shown right on the page.
* Works with any uploads folder layout, not just WordPress's default year/month structure.
* A handful of filters for developers: `wwg_scan_directories`, `wwg_clear_cache_for_attachment`, `wwg_clear_all_cache`, `wwg_attempt_recovery`, `wwg_allow_failure_actions`, `wwg_enabled_formats`.

== Installation ==

1. Install and activate the plugin (Plugins → Add New → search "WebP Generator", or upload the zip manually).
2. Visit **Tools → WebP Generator**.
3. If prompted, set up server-side serving under "Rewrite Rules" (one click on Apache/LiteSpeed).
4. Click **Generate** to catch up your existing media library.

That's it — new uploads are handled automatically from here on.

== Frequently Asked Questions ==

= Does this work on Nginx? =

The plugin still generates the files (WebP, and AVIF if your server supports it), but it can't edit Nginx's config for you the way it can with Apache/LiteSpeed's `.htaccess` — Nginx has no per-directory config file for a plugin to write to. The Rewrite Rules page links you to WordPress.org's own Nginx configuration guide for the equivalent setup.

= Does this replace or delete my original images? =

Not automatically, and never a healthy one. `.webp`/`.avif` files are written alongside the originals; nothing existing is ever deleted, replaced, or modified as a side effect of Generate.

The one deliberate exception: a file that's already proven corrupt and unrecoverable (it failed normal conversion *and* the embedded-data recovery attempt) gets a "Fix this file" / "Delete this file" action in the "Failed conversions" list, so you're not left with no way to clear it out. Fixing a broken thumbnail-size file regenerates it from its healthy original — the original itself is never touched. Deleting is available too, always behind a confirmation, and if the corrupt file turns out to be the original image itself, deleting it removes the whole attachment (confirmed with a stronger, more explicit warning first) rather than leaving a broken remainder behind. Every action is a deliberate, per-file click — never automatic, and never offered for a file that isn't already confirmed broken. Can be turned off entirely with the `wwg_allow_failure_actions` filter.

= What happens to the generated files or my .htaccess rule if I uninstall the plugin? =

Nothing — uninstalling only removes the plugin's own settings. Your generated `.webp`/`.avif` files and any `.htaccess` rule it added stay exactly as they are.

= What if my server doesn't support WebP or AVIF encoding? =

The plugin checks whether Imagick or GD on your server was actually compiled with WebP support, and separately whether either supports AVIF, and tells you plainly on the settings page if neither format works at all — most hosts support at least WebP. Generate is disabled entirely only if nothing works. If just one format isn't available (AVIF support is newer and less universal than WebP's), the plugin quietly keeps using whichever one does — no error, no setting to change, nothing broken; you're just not getting the smaller AVIF files yet, and the moment your host adds support, this plugin picks it up automatically on its own.

= What happens if I close the tab while Generate is running? =

It keeps going. While Tools → WebP Generator is open, Generate runs at full speed; close the tab (or navigate away) and it falls back to running as a background job via WordPress's own WP-Cron instead of stopping — come back any time and it'll show you exactly where things stand (still running, paused, or finished). The background pace is deliberately more modest than the full-speed, tab-open pace (WordPress's own cron self-throttles to avoid piling up overlapping requests), so a very large library will finish faster if you leave the tab open, but it will finish either way. On a host with `DISABLE_WP_CRON` set (common on some managed WordPress hosts, which instead expect a real system cron job hitting `wp-cron.php` on a schedule), the background pace depends on how often that schedule visits the site rather than firing itself immediately.

= Which page cache plugins are supported? =

Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, and LiteSpeed Cache. Sites without a supported cache plugin work fine too — there's just nothing to auto-clear.

== Screenshots ==

1. Tools → WebP Generator: check a media library and generate missing WebP images with live progress.
2. Rewrite Rules: one-click server setup, with a syntax-highlighted example for manual configuration.
3. Settings: configurable quality per format.

== Changelog ==

= 1.15.0 =
* Adds AVIF alongside WebP -- generated automatically for every new upload the moment your server's Imagick or GD actually supports it, no setting to find or turn on. Fully integrated everywhere WebP already was: one Library status, one Scan/Generate pipeline, one Failed Conversions list (with a small format badge on any entry once more than one format is active), a per-format quality slider, and the `.htaccess` rewrite rule now serves AVIF first when both exist for a file, falling back to WebP, then the original. If your server gains AVIF support after the rule was already installed, the Rewrite Rules card notices and offers to update it. "Fix this file" now regenerates every currently-missing format for that file in one click, and "Delete this file" clears all of them together. A site that would rather control this itself can narrow (never widen) which formats are actually produced via the new `wwg_enabled_formats` filter.

= 1.14.0 =
* Each entry in "Failed conversions" now offers a real next step instead of just an error message: "Fix this file" regenerates a broken thumbnail-size image straight from its healthy original (and converts the fresh result to `.webp`), or "Delete instead"/"Delete this file" clears it out. If the corrupt file turns out to be the original image itself, deleting it removes the whole attachment, with a stronger, more explicit confirmation first. Every action is a deliberate per-file click, gated behind the same confirmation a destructive action always gets, and never offered for anything that isn't already a confirmed, permanent failure. See the FAQ for exactly what this does and doesn't touch, and the new `wwg_allow_failure_actions` filter to disable it entirely.

= 1.13.0 =
* Tools → WebP Generator has been reorganized around three clear questions instead of one line of text that got overwritten depending on which tool ran most recently: what's currently true about your library ("Library status"), what you can do right now (Scan/Generate/Cancel), and what a completed Generate run actually did, dated ("Last run"). Library status now persists across reloads the same way a completed Generate run already does, instead of resetting to blank every time -- Scan is no longer limited to once per page load, and Generate can tell right away whether there's anything to do without needing a fresh Scan first.
* If a Generate run changes the library, the last Scan result is marked stale immediately (not just eventually) and says so plainly, rather than silently showing an outdated count.

= 1.12.0 =
* A file that fails to convert once and hasn't changed since is no longer re-attempted on every later Scan/Generate run -- it's still reported every time (so it doesn't silently disappear from view), but without repeating the expensive, doomed decode attempt. Once a failure has held steady across a run, the folder it's in can also join the "already verified" cache from 1.11.0 -- a folder isn't kept out of that cache forever just because one file in it will never convert. Automatically re-attempts for real the moment the file actually changes, even for a folder that's already cached this way.
* A completely empty (0-byte) source file -- typically an interrupted upload or thumbnail generation -- now gets a distinct, actionable message instead of a generic decode-failure error.
* When a file fails to convert normally, the tool now also checks whether a complete, valid image is embedded further into the file (e.g. behind leftover HTTP headers or other stray bytes prepended ahead of otherwise-intact image data) and, if so, generates the `.webp` from that recovered data. Anything created this way is flagged separately in a new "Recovered from embedded data" panel, since the original file itself is still worth a look. Never modifies the original file. Can be disabled via the new `wwg_attempt_recovery` filter.
* Scan now recognizes a known permanent failure the same way Generate does, instead of lumping it into a plain "missing" count -- if an earlier run already found a file un-convertible, Scan calls that out (with the same "Failed conversions" detail) before you even click Generate.

= 1.11.0 =
* Scan and Generate now remember which folders they've already fully verified clean, and skip straight past them on later runs instead of re-checking every file every time -- on a mature library, most folders are old and effectively never change again, so a repeat run can now finish dramatically faster. Self-invalidating: if anything actually changes in a folder (a new upload, a migration, a restore), it's automatically rechecked for real on the next run, no matter how the change happened.
* Generate now runs at full speed while Tools → WebP Generator stays open, instead of always waiting on WordPress's own cron self-throttle (roughly one batch a minute) even while you're actively watching -- closing the tab is still exactly what falls back to that slower, unattended background pace.
* Scan no longer gets stuck permanently disabled once a Generate run finishes -- previously, since progress now persists across reloads, there was no way back to a fresh count without clearing server state by hand.
* Progress text during and after a run is clearer about what's actually still happening: no more "0 images generated so far…" sitting next to a rapidly-changing folder name once every missing image has already been found (it's just finishing the safety-net folder walk at that point, not converting anything new), and the final summary no longer repeats the same sentence twice in a row.

= 1.10.0 =
* Generate now runs as a background job (WordPress's own WP-Cron) instead of only while its browser tab stays open -- a large library keeps converting even if you close the tab or navigate away.
* The Tools → WebP Generator page reflects whatever's actually happening when you load it: still running (with live progress resuming automatically), paused, or finished -- no need to re-click Generate to see where things stand.
* If a background run finishes while you're elsewhere in wp-admin, a dismissible notice and a count badge on the Tools menu let you know, without needing to keep the tool page open to find out.

= 1.9.1 =
* Generate's progress bar now tracks images processed against the count Scan found, instead of folders walked -- on a large library most folders already have nothing left to do, so the old folder-based bar could climb steadily while the "images generated" count stayed at 0, looking stalled even while it worked correctly.
* Failed conversions now record why (the underlying Imagick/GD error) and list them in an expandable "Failed conversions" panel, instead of only a bare count with no way to diagnose it.

= 1.9.0 =
* Initial release.

== Upgrade Notice ==

= 1.15.0 =
Adds AVIF alongside WebP, generated automatically the moment your server supports it -- no setting to turn on. Fully integrated into the same Scan/Generate tool, quality settings, and rewrite rule WebP already used.

= 1.14.0 =
"Failed conversions" now offers a real fix or delete action per file instead of just an error message -- see the FAQ for exactly what this does and doesn't touch.

= 1.13.0 =
Tools → WebP Generator is reorganized around what's currently true, what you can do, and what a completed run actually did -- Library status now persists across reloads and Scan is no longer limited to once per page load.

= 1.12.0 =
Files that permanently fail conversion are no longer re-attempted every run, empty files get a clearer message, and the tool now tries to recover a usable image from files with valid data buried after stray bytes.

= 1.11.0 =
Scan and Generate now skip folders already confirmed clean on a previous run, instead of re-checking everything every time -- repeat runs on a mature library are dramatically faster.

= 1.10.1 =
Generate now runs at full speed while its tab stays open (falling back to a slower background pace once you close it), Scan no longer gets stuck disabled after a run finishes, and progress text is clearer about what's actually happening.

= 1.10.0 =
Generate now runs in the background via WP-Cron and survives closing the tab; the tool page reflects live/paused/finished state on load, and finishing elsewhere in wp-admin shows a notice + menu badge.

= 1.9.1 =
Generate's progress now tracks images instead of folders, and failed conversions show a reason.

= 1.9.0 =
Initial release.
