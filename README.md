# Acquire Cloudflare Cache Manager

Cloudflare cache purging plugin for standalone WordPress sites and WordPress multisite networks.

## What it does

- Works on standalone WordPress installs and is network-activated only on multisite.
- Uses existing per-subsite `cloudflare_zone_id` values from older plugin versions.
- Auto-engages subsites in `Auto` mode when a Zone ID already exists.
- Allows each subsite to be `Auto`, `Enabled`, or `Disabled`.
- Adds subsite toolbar purge options for enabled sites.
- Automatically purges related URLs on public content updates.
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

## GitHub release update workflow

1. Put this folder in a GitHub repo named `acquire-cloudflare-cache-manager`.
2. Update the version number in the plugin header and `const VERSION` when you make changes.
3. Zip the plugin folder so the zip contains this root folder:
   `acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php`
4. Create a GitHub Release with a tag such as `v3.0.1`.
5. Attach the zip file as a release asset.
6. WordPress will detect the release as an available plugin update where the GitHub repo is configured or baked into the plugin.

A public GitHub repo is the simplest option. Private repos can be used for release checks with a token, but the update package download is most reliable when the release zip asset is publicly reachable or served through a private updater endpoint.


## Baked-in GitHub updater repo

This build defaults to `djknucklehead/acquire-cloudflare-cache-manager` for update checks. You can still override it with the `ACFCM_GITHUB_REPO` constant or the Network Admin settings page.


## Standalone WordPress behavior

On a standalone WordPress install, the plugin uses **Settings → Cloudflare Cache** for everything:

- Site mode, Zone ID, token source, content purge, and logged-in no-cache settings.
- WordPress update purge settings for core/plugin/theme/translation updates.
- GitHub update source settings.
- Recent purge log.

On multisite, those global/update settings remain under **Network Admin → Settings → Cloudflare Cache Manager**.


## Automatic GitHub release packaging

This repository includes a GitHub Actions workflow at `.github/workflows/package-release.yml`.

Future release flow:

1. Update the version in the plugin header and `const VERSION`.
2. Update `CHANGELOG.md`.
3. Commit and push to `main`.
4. On GitHub.com, create a new release using a tag like `v3.0.3`.
5. Publish the release without manually attaching a zip.
6. GitHub Actions will build `acquire-cloudflare-cache-manager-v3.0.3.zip` and attach it to the release automatically.

The workflow validates that the release tag matches the plugin version before uploading the zip.

## Plugin icon

The updater sends `assets/icon.svg` as the plugin icon for WordPress update/details screens. Replace that SVG with your preferred icon artwork and commit it to the repo.
