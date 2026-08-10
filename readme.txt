=== WebP Generator ===
Contributors: abecoffman
Tags: webp, image optimization, performance, media, images
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates .webp versions of your images automatically, catches up your existing media library on demand, and helps your server actually serve them.

== Description ==

WebP Generator creates a `.webp` version of every image you upload — automatically, for the original file and every size WordPress generates — using Imagick or GD (no shell access or external binary required). New uploads are covered from the moment you activate the plugin.

For everything uploaded *before* that, **Tools → WebP Generator** adds a simple Scan / Generate / Cancel tool:

* **Scan** reports how many images in your media library are missing a `.webp` version, and how much that adds up to, before anything is written.
* **Generate** converts them in the background — it keeps running even if you close the browser tab, so a large library doesn't need to be babysat. Come back any time and the page picks up exactly where things stand: still running, paused, or finished, no need to click Generate again to see it. If you're elsewhere in wp-admin when it finishes, a notice and a Tools-menu badge let you know. It's resumable: batches are small and bounded so it can't time out on a large library, and if you click **Cancel**, it pauses exactly where it was — click Generate again any time to pick up from that point.

= Serving the files =

Generating `.webp` files is only half the job — browsers only get them if your server is configured to serve them instead of the original. WebP Generator can set this up for you with one click on Apache and LiteSpeed (both read the same `.htaccess`/mod_rewrite syntax). It's reversible any time, and only ever touches one clearly-marked block of your `.htaccess`, the same mechanism WordPress core uses for its own rewrite rules. On Nginx or IIS, the plugin links you straight to the official documentation for configuring the equivalent rule.

= Plays well with page caching =

If your site runs Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, or LiteSpeed Cache, newly-generated `.webp` files automatically trigger a cache clear — otherwise a page cached before the file existed could keep serving the old format until that cache entry expired on its own.

= Also included =

* Configurable WebP quality (1-100, default 75).
* Works with any uploads folder layout, not just WordPress's default year/month structure.
* A handful of filters for developers: `wwg_scan_directories`, `wwg_clear_cache_for_attachment`, `wwg_clear_all_cache`, `wwg_attempt_recovery`.

== Installation ==

1. Install and activate the plugin (Plugins → Add New → search "WebP Generator", or upload the zip manually).
2. Visit **Tools → WebP Generator**.
3. If prompted, set up server-side serving under "Rewrite Rules" (one click on Apache/LiteSpeed).
4. Click **Scan**, then **Generate** to catch up your existing media library.

That's it — new uploads are handled automatically from here on.

== Frequently Asked Questions ==

= Does this work on Nginx? =

The plugin still generates the `.webp` files, but it can't edit Nginx's config for you the way it can with Apache/LiteSpeed's `.htaccess` — Nginx has no per-directory config file for a plugin to write to. The Rewrite Rules page links you to WordPress.org's own Nginx configuration guide for the equivalent setup.

= Does this replace or delete my original images? =

No. `.webp` files are written alongside the originals; nothing existing is ever deleted, replaced, or modified.

= What happens to the .webp files or my .htaccess rule if I uninstall the plugin? =

Nothing — uninstalling only removes the plugin's own settings. Your generated `.webp` files and any `.htaccess` rule it added stay exactly as they are.

= What if my server doesn't support WebP encoding? =

The plugin checks whether Imagick or GD on your server was actually compiled with WebP support, and tells you plainly on the settings page if neither was — most hosts have at least one. Scan/Generate are disabled until that's resolved, rather than letting you run a tool that can't do anything.

= What happens if I close the tab while Generate is running? =

It keeps going. While Tools → WebP Generator is open, Generate runs at full speed; close the tab (or navigate away) and it falls back to running as a background job via WordPress's own WP-Cron instead of stopping — come back any time and it'll show you exactly where things stand (still running, paused, or finished). The background pace is deliberately more modest than the full-speed, tab-open pace (WordPress's own cron self-throttles to avoid piling up overlapping requests), so a very large library will finish faster if you leave the tab open, but it will finish either way. On a host with `DISABLE_WP_CRON` set (common on some managed WordPress hosts, which instead expect a real system cron job hitting `wp-cron.php` on a schedule), the background pace depends on how often that schedule visits the site rather than firing itself immediately.

= Which page cache plugins are supported? =

Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, and LiteSpeed Cache. Sites without a supported cache plugin work fine too — there's just nothing to auto-clear.

== Screenshots ==

1. Tools → WebP Generator: scan a media library and generate missing WebP images with live progress.
2. Rewrite Rules: one-click server setup, with a syntax-highlighted example for manual configuration.
3. Settings: configurable WebP quality.

== Changelog ==

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
