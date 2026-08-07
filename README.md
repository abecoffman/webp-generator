# WebP Generator

Generate `.webp` versions of your WordPress media library, automatically for new uploads and on demand for everything already there — plus a one-click way to actually get your server to serve them.

## What it does

- **Every new upload** gets a `.webp` sibling generated automatically for the original file and every registered size, via Imagick (preferred) or GD — no `cwebp` binary or shell access required.
- **Tools → WebP Generator** adds a Scan / Generate / Cancel screen to catch up your existing media library: scan reports how many images are missing a `.webp` version before you commit to anything, Generate runs the conversion with live progress, Cancel pauses (not discards) an in-progress run so you can resume exactly where you left off.
- **Serving**: WordPress creating the files is only half the job — a browser only gets a `.webp` if the server is configured to serve it instead of the original. On Apache and LiteSpeed (both `.htaccess`/mod_rewrite-compatible), the plugin can add that rule for you with one click, reversible any time. On Nginx or IIS it links you to the official docs for configuring the equivalent rule yourself.
- **Page cache aware**: if the site runs Cache Enabler, WP Rocket, W3 Total Cache, WP Super Cache, or LiteSpeed Cache, newly-generated `.webp` files trigger an automatic cache clear so already-cached pages don't keep serving the old format.
- **Configurable quality**, sane default (75), applies to images converted from that point forward.

## Screenshots

See [`.wordpress-org/screenshot-*.png`](.wordpress-org/) or the plugin's listing on WordPress.org.

## Installation

**From WordPress.org** (once approved): Plugins → Add New → search "WebP Generator" → Install → Activate.

**Manual install**: download the [latest release](../../releases/latest), upload the zip via Plugins → Add New → Upload Plugin, then activate. Or clone/copy this repo into `wp-content/plugins/webp-generator/`.

After activating, visit **Tools → WebP Generator** to scan your existing library and set up server-side serving.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Imagick or GD with WebP support compiled in (most hosts have one or the other; the plugin tells you which on its settings page, or if neither is available)

## Development

```
composer install   # dev dependencies: WPCS, PHPCompatibility, PHPUnit, Brain Monkey
composer run phpcs  # coding standards
composer run test   # unit tests
bin/build-zip.sh    # build a clean, WordPress.org-ready dist/webp-generator-<version>.zip
```

No JS/CSS build step — `assets/admin.js`/`admin.css` are plain, unbundled files. `bin/build-zip.sh` is packaging only: it strips dev-only files per `.distignore` (tests, CI config, etc.) into a zip that's actually installable. Pushing a `vX.Y.Z` tag runs the full CI suite (coding standards, PHPUnit, [Plugin Check](https://wordpress.org/plugins/plugin-check/)) and, if it's all green, publishes that zip as a GitHub release automatically.

Internal PHP identifiers (classes, constants, hooks) use a `WWG_`/`wwg_` prefix from this plugin's working name before "wp" turned out to be a restricted term for the public-facing plugin name/slug on WordPress.org. Cosmetic only — the prefix itself is still valid and collision-free, so it wasn't worth the risk of a mechanical rename across the whole codebase.

## Documentation & support

Full FAQ and changelog live in [`readme.txt`](readme.txt) (the WordPress.org plugin format). Found a bug or want a feature? [Open an issue](../../issues).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
