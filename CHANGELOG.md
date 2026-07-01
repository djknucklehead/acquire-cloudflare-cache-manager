# Changelog

## 3.1.1
- Fixed Cloudflare cache rule creation by using an API-valid 2xx status code TTL range instead of a single-code range with equal endpoints.

## 3.1.0
- Added recommended Cloudflare Cache Rules setup for each configured Zone ID.
- Added subsite and Network Admin actions to create or update the `Cache Everything [Template]` and `BYPASS` cache rules while preserving other Cloudflare cache rules.
- Kept common static, font, and video file extensions out of the bypass rule so those assets remain cacheable.
- Documented the additional Cloudflare API token permissions needed for cache rule setup.

## 3.0.4
- Updated the plugin update icon to the Acquire Digital logo.

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
