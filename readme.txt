=== WebP Generator ===
Contributors: abecoffman
Tags: webp, image optimization, performance, media, images
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates .webp versions of your images automatically, catches up your existing media library on demand, and helps your server actually serve them.

== Description ==

WebP Generator creates a `.webp` version of every image you upload — automatically, for the original file and every size WordPress generates — using Imagick or GD (no shell access or external binary required). New uploads are covered from the moment you activate the plugin.

For everything uploaded *before* that, **Tools → WebP Generator** adds a simple Scan / Generate / Cancel tool:

* **Scan** reports how many images in your media library are missing a `.webp` version, and how much that adds up to, before anything is written.
* **Generate** converts them, with a live progress bar and running count. It's resumable: batches are small and bounded so it can't time out on a large library, and if you click **Cancel**, it pauses exactly where it was — click Generate again any time to pick up from that point.

= Serving the files =

Generating `.webp` files is only half the job — browsers only get them if your server is configured to serve them instead of the original. WebP Generator can set this up for you with one click on Apache and LiteSpeed (both read the same `.htaccess`/mod_rewrite syntax). It's reversible any time, and only ever touches one clearly-marked block of your `.htaccess`, the same mechanism WordPress core uses for its own rewrite rules. On Nginx or IIS, the plugin links you straight to the official documentation for configuring the equivalent rule.

= Plays well with page caching =

If your site runs Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, or LiteSpeed Cache, newly-generated `.webp` files automatically trigger a cache clear — otherwise a page cached before the file existed could keep serving the old format until that cache entry expired on its own.

= Also included =

* Configurable WebP quality (1-100, default 75).
* Works with any uploads folder layout, not just WordPress's default year/month structure.
* A handful of filters for developers: `wwg_scan_directories`, `wwg_clear_cache_for_attachment`, `wwg_clear_all_cache`.

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

= Which page cache plugins are supported? =

Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, and LiteSpeed Cache. Sites without a supported cache plugin work fine too — there's just nothing to auto-clear.

== Screenshots ==

1. Tools → WebP Generator: scan a media library and generate missing WebP images with live progress.
2. Rewrite Rules: one-click server setup, with a syntax-highlighted example for manual configuration.
3. Settings: configurable WebP quality.

== Changelog ==

= 1.9.0 =
* Initial release.

== Upgrade Notice ==

= 1.9.0 =
Initial release.
