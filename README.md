# Acquire Cloudflare Cache Manager

Cloudflare cache purging plugin for standalone WordPress sites and WordPress multisite networks.

## What it does

- Works on standalone WordPress installs and is network-activated only on multisite.
- Uses existing per-subsite `cloudflare_zone_id` values from older plugin versions.
- Auto-engages subsites in `Auto` mode when a Zone ID already exists.
- Allows each subsite to be `Auto`, `Enabled`, or `Disabled`.
- Adds subsite toolbar purge options for enabled sites.
- Automatically purges related URLs on public content updates.
- Creates or updates the recommended Cloudflare `Cache Everything [Template]` and `BYPASS` cache rules for configured Zone IDs.
- Creates or updates optional Cloudflare hardening rules for common WordPress exploit probes, XML-RPC, and query-string abuse on static/legal pages.
- Purges all enabled Cloudflare zones after WordPress core/plugin/theme updates.
- Includes manual purge controls in Network Admin on multisite and in Settings on standalone installs.
- Includes GitHub release update checking.

## Recommended wp-config.php constants

Add this to each standalone site's or multisite network's `wp-config.php`:

```php
define( 'ACFCM_CLOUDFLARE_API_TOKEN', 'YOUR_CLOUDFLARE_API_TOKEN' );
```

Optional GitHub updater constants:

```php
define( 'ACFCM_GITHUB_REPO', 'YourGitHubUsername/acquire-cloudflare-cache-manager' );
// Only needed for private repo release checks:
define( 'ACFCM_GITHUB_TOKEN', 'YOUR_GITHUB_TOKEN' );
```

The plugin is also backward-compatible with the older `CLOUDFLARE_API_TOKEN` constant.

For purge-only use, the token can be limited to cache purge access. To use the recommended cache rule setup action, the token also needs Cloudflare's `Zone > Cache Rules > Edit`, `Account Rulesets > Edit`, and `Account Filter Lists > Edit` permissions for the relevant zone/account. To use hardening rule setup, the token needs `Zone > WAF > Edit`; the high-rate query-string option may also require `Zone > Rate Limiting Rules > Edit` and plan support for rate limiting rules.

## GitHub release update workflow

1. Put this folder in a GitHub repo named `acquire-cloudflare-cache-manager`.
2. Update the version number in the plugin header and `const VERSION` when you make changes.
3. Zip the plugin folder so the zip contains this root folder:
   `acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php`
4. Create a GitHub Release with a tag such as `v3.2.0`.
5. Attach the zip file as a release asset.
6. WordPress will detect the release as an available plugin update where the GitHub repo is configured or baked into the plugin.

A public GitHub repo is the simplest option. Private repos can be used for release checks with a token, but the update package download is most reliable when the release zip asset is publicly reachable or served through a private updater endpoint.


## Baked-in GitHub updater repo

This build defaults to `djknucklehead/acquire-cloudflare-cache-manager` for update checks. You can still override it with the `ACFCM_GITHUB_REPO` constant or the Network Admin settings page.


## Standalone WordPress behavior

On a standalone WordPress install, the plugin uses **Settings → Cloudflare Cache** for everything:

- Site mode, Zone ID, token source, recommended cache rule setup, content purge, and logged-in no-cache settings.
- WordPress update purge settings for core/plugin/theme/translation updates.
- GitHub update source settings.
- Recent purge log.

On multisite, those global/update settings remain under **Network Admin → Settings → Cloudflare Cache Manager**.

## Recommended Cloudflare cache rules

After saving a Zone ID for a standalone site or multisite subsite, use **Save & Install Recommended Cache Rules** on the site settings screen, or **Install Rules** from the Network Admin subsite table.

The plugin creates or updates these two cache rules in Cloudflare's cache settings phase:

- `Cache Everything [Template]`: makes requests eligible for cache, tries to ignore query strings in the cache key, sets a 7-day default edge TTL, caches 2xx responses for 1 day, and avoids caching 300+ responses.
- `BYPASS`: runs after the cache-everything rule and bypasses cache for WordPress admin/login/API/preview/logged-in requests while leaving static assets cacheable.

Other existing Cloudflare cache rules are preserved. If Cloudflare reports that a zone is not entitled to custom cache key overrides, the installer retries without the ignore-query-string cache key setting.


## Optional Cloudflare hardening rules

Use **Cloudflare Hardening Rules** on the site settings screen, or the Network Admin hardening section for multisite, to install selected Cloudflare-level protections.

The plugin can create or update these deterministic Cloudflare rules:

- `ACFCM - Block WordPress exploit probes`: blocks random root PHP probes, direct PHP execution probes under `/wp-content/` and `/wp-includes/`, fake `/wp-admin/` probe files, and old install-path probes.
- `ACFCM - Block XML-RPC`: blocks direct requests to `/xmlrpc.php`.
- `ACFCM - Challenge legal-page query strings`: uses a managed challenge for query-string requests to `/privacy-policy/` and `/terms-and-conditions/`.
- `ACFCM - Rate limit legal-page query strings`: uses Cloudflare rate limiting to managed-challenge repeated query-string traffic to those same legal pages.

The hardening installer preserves existing Cloudflare WAF and rate limiting rules. It replaces matching selected ACFCM-managed rules by description so the installer can be re-run safely. Verified bots are excluded with Cloudflare's `cf.client.bot` field.


## Automatic GitHub release packaging

This repository includes a GitHub Actions workflow at `.github/workflows/package-release.yml`.

Future release flow:

1. Update the version in the plugin header and `const VERSION`.
2. Update `CHANGELOG.md`.
3. Commit and push to `main`.
4. On GitHub.com, create a new release using a tag like `v3.2.0`.
5. Publish the release without manually attaching a zip.
6. GitHub Actions will build `acquire-cloudflare-cache-manager-v3.2.0.zip` and attach it to the release automatically.

The workflow validates that the release tag matches the plugin version before uploading the zip.

## Plugin icon

The updater sends `assets/icon.svg` as the plugin icon for WordPress update/details screens. Replace that SVG with your preferred icon artwork and commit it to the repo.
