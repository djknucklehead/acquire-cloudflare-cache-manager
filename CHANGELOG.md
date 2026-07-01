# Changelog

## 3.0.3
- Added GitHub Actions release packaging workflow.
- Added plugin icon metadata support for GitHub-hosted update details.
- Added `assets/icon.svg` as the default plugin icon source.

## 3.0.2
- Improved standalone WordPress UI.
- Added standalone settings for update-triggered purges, external cache hook purges, GitHub update source, and purge log.
- Updated plugin description and README to clarify standalone + multisite support.

## 3.0.1
- Baked in default GitHub repo: `djknucklehead/acquire-cloudflare-cache-manager`.
- Network UI/wp-config repo setting remains available as an override.

## 3.0.0

- Consolidated previous subsite Cloudflare purge plugin behavior into one network-activated plugin.
- Added Auto/Enabled/Disabled per-subsite mode.
- Added automatic detection of existing `cloudflare_zone_id` values.
- Added network-wide purge after WordPress core/plugin/theme updates.
- Added network dashboard with subsite mode, Zone ID, and purge controls.
- Added GitHub release update checking.
- Kept backward compatibility with existing `cloudflare_zone_id`, `cloudflare_api_token`, and `CLOUDFLARE_API_TOKEN` usage.
