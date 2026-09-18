# Changelog

## 3.7.4 — 2026-09-18

- Install the full recommended security set in one click from network site rows or subsite settings: all three legacy custom protections, sensitive-file blocking, submission/session rate exceptions and public-page rate limiting. Add missing protections without duplicating matching legacy rules.
- Remove the separate security review/approval UI. Automatically verify current zone identity/status, save a bounded backup and recheck scope and rules before each addition. Preserve unrelated rules and report quota/customization conflicts without overwriting them. Rename the cache action to Install cache.

## 3.7.3 — 2026-09-18

- Clear known current-site WordPress object-cache entries before the explicit site-level WP Engine + Cloudflare purge, including autoloaded/individual options, posts and metadata, terms, comments, taxonomy relationships and core query versions. Read existing record IDs in batches after direct database search-replace.
- Never flush the backend, customer-wide generation, network/global groups or other subsites. Arbitrary third-party opaque keys and individually cached deleted records cannot be safely enumerated and are not included. Keep network/maintenance purges unchanged; stop before page/CDN dispatch if object-cache invalidation cannot be verified.

## 3.7.2 — 2026-09-18

- Allow fresh security-only review and explicit approval for historically excluded zones after verifying their current active/unpaused status, expected account, zone ID, domain and WordPress scope. Pending, paused, moved and mismatched zones remain blocked.
- Add per-site/network-row security review and approval controls, with user-bound, expiring approvals and status/ruleset rechecks before writes. Preserve security ownership, quota, drift and idempotence protections; leave cache migrations and purges untouched.

## 3.7.1 — 2026-09-18

- Add one-click Install cache rules and Install security rules to each network site's Actions column and to subsite settings. No preview or agreement checkbox is required for installation.
- Use the standard six-rule public-page cache policy: seven-day eligible public HTML, 24-hour Gravity Forms HTML, supported tracking-query cache sharing, and dynamic/session/private-response bypasses.
- Automatically back up and replace existing request/response cache rules, including custom exceptions. Install and verify the persistent WordPress guard before Cloudflare writes; retain progress, drift checks, Resume, backup download and rollback.
- Keep security installation separate and preserve existing security policies. Updating the plugin does not automatically install or replace Cloudflare rules.
- Add one combined manual cache-clear action: dispatch WP Engine page-cache purging for the selected domain first, then Cloudflare purging for its configured zone in the same request. Preserve bounded retries, cooldowns and accurate failure/pending notices.
- Improve network and subsite administration with responsive tables, individual-URL refresh, visible maintenance status and recovery controls. Protect active cache-policy settings and validate site/zone permissions.
- Install the persistent guard using a locked, verified rename without requiring hard links. Pause failed installations for recovery.
- Version admin assets by content hash so replaced builds cannot reuse stale button scripts.
- Advance from the manually distributed 3.7.0 candidate to 3.7.1 so candidate installations also receive the updater release.

## 3.6.0

- Replace destructive hardening-rule description matching with read-only adoption of the verified September 17 defense rollout, including all consolidated blocks and retained policies.
- Add explicit Free-plan baseline onboarding using create-only requests, quota/ownership preflight, fresh drift checks, per-zone installer locking and readback verification. Partial or uncertain failures stop without automatic rollback or retry.
- Preserve bettertomorrowinamerica.com's all-path rate policy and blackbearpac.com's legacy rule reference. Legacy recommendations are review-only; no automatic rule splitting, consolidation or policy replacement.
- Replace legacy hardening choices with a defense verification/onboarding control; explain narrow rate exceptions, ownership and manual conflict review. Combined cache/defense installation checks defenses first.
- No automatic firewall changes on upgrades, content edits or purges. Existing targeted WP Engine-before-Cloudflare purges and paced maintenance queues are unchanged.
- Add saved rollout fixtures, mocked API regressions, local expression/trace checks and standalone/multisite WordPress integration coverage. No production changes are part of this candidate.


## 3.5.0
- Replace network maintenance purge loops with one durable, atomically claimed queue per WordPress network. Pace both origin dispatch and hostname-scoped Cloudflare purges from actual execution time, with no catch-up burst.
- Coalesce plugin/theme/core update sessions using start/completion hooks, a renewable quiet period, and generation checks. New updates supersede pending edge work and refresh previously processed sites without stacking queues.
- Add configurable 120-second spacing and 180-second quiet defaults, update-screen hold/finished controls, progress, pause/resume/cancel and explicit failed-target retry.
- Preserve targeted content edits and explicit single-site manual purges. Shared zones retain every unique origin hostname; shared-host/subdirectory maintenance scopes fail for review rather than broadening to unrelated hosts.
- Add bounded provider-failure backoff, worker/update lease recovery, and simulated-provider integration tests. Native WP Engine/SPM purges remain independent; no object-cache flushing is added.

## 3.4.4
- Automatically dispatch a scoped WP Engine page-cache purge before Cloudflare for content changes and manual purges when the WP Engine transport is available.
- Persist a five-second minimum propagation interval; Cloudflare follows on a later eligible cron pass. Manual purges on WP Engine now report queued while waiting.
- Retry reported WP Engine dispatch failures without sending Cloudflare first, and repeat the origin stage for newer edits while avoiding recursive external-cache hooks.
- Scope WP Engine requests to the current site's hostname and affected paths, including old URLs and query variants; site-wide purges respect subdirectory boundaries. Object cache and separate WP Engine network/CDN cache products are not flushed.

## 3.4.3
- Capture old public URLs before WordPress can append trashed-slug suffixes, combine final URLs at shutdown, and defer content HTTP purges to per-site cron. Covers slug, author, taxonomy and thumbnail changes and transitions away from publish.
- Replace modified-time suppression with bounded persistent, deduplicated purge jobs, partial-batch recovery, exponential backoff, Retry-After handling and per-site cron retries.
- Repair missing cron events on origin requests and use unique inserts plus fresh database reads for queue slots to tolerate concurrent requests with or without persistent object caching.
- Keep accepted attempts with trailing content pending until completion.
- Add bounded site/zone/URL diagnostics, pending/retry outcomes and safe failure codes; restrict site-admin logs to their own site.
- Run network purge jobs in the target site context and document cache-key limits, mandatory per-site cron, real local integration tests, network-wide installation scope and rollback.
- Exclude development/test harnesses from release packages through an explicit file allowlist.

## 3.4.2
- Replaced the aggressive ignore-all-query-strings cache key override with a safer marketing-parameter exclusion list for ad and analytics query strings.
- Updated the cache key payload to Cloudflare's current Rulesets API shape for named query-string exclusions.
- Preserved functional query strings such as WordPress asset versions, search, filters, pagination, previews, AJAX, and cart actions in the cache key.

## 3.4.1
- Fixed Smart Tiered Cache enablement by turning on Cloudflare's base Tiered Cache setting before selecting the Smart topology.
- Clarified Smart Tiered Cache labels and notices so they describe both zone-level Cloudflare settings.

## 3.4.0
- Added opt-in Smart Tiered Cache enablement for standalone sites and multisite subsites.
- Recommended cache rule installation now enables Cloudflare's zone-level Smart Tiered Cache setting for zones with at least one opted-in enabled site.
- Added warning notices when cache rules install but Cloudflare cannot enable Smart Tiered Cache.

## 3.3.1
- Updated the recommended `Cache Everything [Template]` status-code TTL policy: 2xx responses cache for 1 day, 301 and 304 cache for 1 day, 404 and 410 cache for 2 hours, and other 300+ responses bypass cache.
- Added a 50 KB minimum file size to plugin-managed Cache Reserve eligibility rules.
- Added a fallback so recommended cache rules still install without Cache Reserve eligibility when Cloudflare reports that Cache Reserve is not enabled or not entitled for the zone.

## 3.3.0
- Added opt-in Cloudflare Cache Reserve eligibility for standalone sites and individual multisite subsites.
- Added hostname-specific managed Cache Reserve rules so one domain can use Cache Reserve even when multiple sites share a Cloudflare zone.
- Kept Cache Reserve eligibility rules ahead of the WordPress bypass rule so admin, login, API, preview, and logged-in traffic remain uncacheable.
- Rebuilds all plugin-managed Cache Reserve hostname rules for a zone when recommended cache rules are installed, removing stale hostname rules while preserving unrelated Cloudflare rules.

## 3.2.6
- Allowed multisite subsite admins to run manual purge actions for their own site when a shared network or `wp-config.php` Cloudflare API token is active, while keeping plugin settings and Cloudflare rule management restricted to Network Admin.

## 3.2.5
- Added a per-subsite Network Admin action to install cache rules together with the basic WordPress exploit-probe Cloudflare hardening rule.
- Clarified that the Network Admin Cloudflare hardening form applies to every enabled zone.

## 3.2.4
- Restricted multisite subsite Cloudflare mode/Zone ID edits and manual Cloudflare purge/rule actions to Network Admin users when a shared network or wp-config Cloudflare API token is active.

## 3.2.3
- Added a fallback for Cloudflare zones that are not entitled to use `http.request.uri.query` in rate limiting rules; the optional legal-page rate limit now retries with a path-only expression while leaving the query-specific WAF challenge rule intact.

## 3.2.2
- Updated the optional legal-page query-string rate limiting rule to use a 10-second period so it can install on Cloudflare zones that are not entitled to 60-second rate limiting periods.

## 3.2.1
- Replaced regex-based WordPress hardening expressions with regex-free Cloudflare expressions so WAF rules can install on zones that do not have access to the `matches` operator.
- Preserved existing Cloudflare WAF/rate limiting rule priority ahead of plugin-managed hardening rules when updating hardening rulesets.

## 3.2.0
- Added optional Cloudflare hardening rule setup from the site settings screen and Network Admin.
- Added selected WAF custom rules for WordPress exploit probes, XML-RPC, and query-string requests to legal pages while preserving existing Cloudflare rules.
- Added optional Cloudflare rate limiting rule setup for repeated query-string traffic to legal pages.

## 3.1.2
- Added a fallback for Cloudflare zones that are not entitled to custom cache key overrides; recommended cache rules now install without the ignore-query-string cache key override when Cloudflare blocks that setting.

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
