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
- Reduces ad and analytics query-string cache fragmentation when Cloudflare entitles the zone to custom cache key settings.
- Optionally makes selected standalone sites or multisite subsite hostnames eligible for Cloudflare Cache Reserve.
- Optionally enables Cloudflare Tiered Cache with Smart topology for selected Cloudflare zones when installing recommended cache rules.
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

For purge-only use, the token can be limited to cache purge access. To use the recommended cache rule setup action, the token also needs Cloudflare's `Zone > Cache Rules > Edit`, `Account Rulesets > Edit`, and `Account Filter Lists > Edit` permissions for the relevant zone/account. Enabling Tiered Cache with Smart topology also needs permission to edit zone settings, shown in Cloudflare as `Zone > Zone Settings > Edit` or `Zone > Cache Settings > Edit` depending on the token UI. To use hardening rule setup, the token needs `Zone > WAF > Edit`; the high-rate query-string option may also require `Zone > Rate Limiting Rules > Edit` and plan support for rate limiting rules.

On multisite, when a shared network or `wp-config.php` Cloudflare API token is active, Network Admin owns subsite Cloudflare mode, Zone ID, plugin settings, and Cloudflare rule installation actions. Site admins can still run manual purge actions for their own subsite. If no shared token is configured, individual subsites can continue to use their own saved Zone ID and per-site token.

## GitHub release update workflow

1. Put this folder in a GitHub repo named `acquire-cloudflare-cache-manager`.
2. Update the version number in the plugin header and `const VERSION` when you make changes.
3. Zip the plugin folder so the zip contains this root folder:
   `acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php`
4. Create a GitHub Release with a tag such as `v3.4.2`.
5. Attach the zip file as a release asset.
6. WordPress will detect the release as an available plugin update where the GitHub repo is configured or baked into the plugin.

A public GitHub repo is the simplest option. Private repos can be used for release checks with a token, but the update package download is most reliable when the release zip asset is publicly reachable or served through a private updater endpoint.


## Baked-in GitHub updater repo

This build defaults to `djknucklehead/acquire-cloudflare-cache-manager` for update checks. You can still override it with the `ACFCM_GITHUB_REPO` constant or the Network Admin settings page.


## Standalone WordPress behavior

On a standalone WordPress install, the plugin uses **Settings → Cloudflare Cache** for everything:

- Site mode, Zone ID, token source, recommended cache rule setup, Cache Reserve eligibility, Tiered Cache Smart topology enablement, content purge, and logged-in no-cache settings.
- WordPress update purge settings for core/plugin/theme/translation updates.
- GitHub update source settings.
- Recent purge log.

On multisite, those global/update settings remain under **Network Admin → Settings → Cloudflare Cache Manager**.

## Recommended Cloudflare cache rules

After saving a Zone ID for a standalone site or multisite subsite, use **Save & Install Recommended Cache Rules** on the site settings screen, or **Install Cache Rules** from the Network Admin subsite table.

In Network Admin, each subsite row also includes **Install Cache + Basic Security**. That action installs the recommended cache rules and the basic WordPress exploit-probe Cloudflare WAF rule for that one subsite zone. XML-RPC blocking, legal-page query-string challenges, and legal-page rate limiting remain opt-in hardening choices because they are more likely to affect site integrations or plan-specific limits.

The plugin creates or updates these cache rules in Cloudflare's cache settings phase:

- `Cache Everything [Template]`: makes requests eligible for cache, tries to exclude common ad and analytics query parameters from the cache key, sets a 7-day default edge TTL, caches 2xx responses for 1 day, caches 301 and 304 for 1 day, avoids caching most other 300+ responses, and caches 404/410 responses for 2 hours.
- `ACFCM - Cache Reserve: hostname`: created for each enabled site that opts into Cache Reserve. It makes only that canonical hostname eligible for Cache Reserve with a 50 KB minimum file size.
- `BYPASS`: runs after the cache-everything rule and bypasses cache for WordPress admin/login/API/preview/logged-in requests while leaving static assets cacheable.

The plugin always orders hostname-specific Cache Reserve rules between `Cache Everything [Template]` and `BYPASS`. If multiple multisite domains use the same Zone ID, installing recommended rules for any one of them rebuilds the complete set of opted-in hostnames for that zone.

When Cloudflare accepts the custom cache key setting, the plugin excludes common tracking parameters such as `utm_source`, `utm_medium`, `utm_campaign`, `gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid`, and `ttclid` from the cache key. It preserves functional query strings, including WordPress asset versions, search, filters, pagination, previews, AJAX, and cart actions. Developers can customize the ignored parameter list with the `acfcm_marketing_query_parameters_to_ignore` filter.

Smart Tiered Cache is not a cache rule. When the Smart Tiered Cache option is enabled for a standalone site or any enabled multisite subsite in a zone, the recommended cache rule installer enables Cloudflare's zone-level Tiered Cache setting and then selects Smart topology for that zone. Unchecking the plugin option stops future installer runs from enabling those settings, but does not turn them off in Cloudflare.

Cache Reserve storage sync and a paid Cache Reserve plan must be enabled separately in Cloudflare for the applicable zone. Cloudflare also requires eligible responses to have a freshness TTL of at least 10 hours and a `Content-Length` response header. The plugin's 2xx/301/304 edge TTL meets the freshness requirement, but the origin must supply `Content-Length`. URL purges sent after public content changes remove matching assets from both edge cache and Cache Reserve.

Other existing Cloudflare cache rules are preserved. If Cloudflare reports that a zone is not entitled to custom cache key overrides, the installer retries without the marketing query-string cache key setting. If Cloudflare reports that Cache Reserve is not enabled or not entitled for the zone, the installer retries without plugin-managed Cache Reserve eligibility rules so the ordinary cache rules can still install.


## Optional Cloudflare hardening rules

Use **Cloudflare Hardening Rules** on the site settings screen, or the Network-Wide Cloudflare Hardening Rules section in Network Admin, to install selected Cloudflare-level protections. In Network Admin, the hardening form applies to every enabled zone; use the subsite table actions for one-zone installs.

The plugin can create or update these deterministic Cloudflare rules:

- `ACFCM - Block WordPress exploit probes`: blocks random root PHP probes, direct PHP execution probes under `/wp-content/` and `/wp-includes/`, fake `/wp-admin/` probe files, and old install-path probes.
- `ACFCM - Block XML-RPC`: blocks direct requests to `/xmlrpc.php`.
- `ACFCM - Challenge legal-page query strings`: uses a managed challenge for query-string requests to `/privacy-policy/` and `/terms-and-conditions/`.
- `ACFCM - Rate limit legal-page query strings`: uses Cloudflare rate limiting to managed-challenge repeated query-string traffic to those same legal pages after 10 requests in 10 seconds. If Cloudflare does not entitle the zone to inspect query strings in rate limiting rules, the installer retries with a path-only legal-page rate limit.

The hardening installer preserves existing Cloudflare WAF and rate limiting rules. It replaces matching selected ACFCM-managed rules by description so the installer can be re-run safely. Verified bots are excluded with Cloudflare's `cf.client.bot` field.


## Automatic GitHub release packaging

This repository includes a GitHub Actions workflow at `.github/workflows/package-release.yml`.

Future release flow:

1. Update the version in the plugin header and `const VERSION`.
2. Update `CHANGELOG.md`.
3. Commit and push to `main`.
4. On GitHub.com, create a new release using a tag like `v3.4.2`.
5. Publish the release without manually attaching a zip.
6. GitHub Actions will build `acquire-cloudflare-cache-manager-v3.4.2.zip` and attach it to the release automatically.

The workflow validates that the release tag matches the plugin version before uploading the zip.

## Plugin icon

The updater sends `assets/icon.svg` as the plugin icon for WordPress update/details screens. Replace that SVG with your preferred icon artwork and commit it to the repo.

## Purge reliability (3.4.3)

Public content changes capture existing public URLs before WordPress rewrites or deletes them, then collect final URLs at request shutdown. Shutdown **queues** the combined old/new permalinks, homepage/feed, posts page, author/taxonomy/post-type archives and thumbnail; it makes no Cloudflare HTTP calls. This includes scheduled publish and transitions to draft/private/trash. Repeated hooks merge within each site. Older modified-time options no longer suppress purges or record completion.

**A functioning per-site cron runner is required for the first content purge and retries.** Edge-cached traffic never reaches WordPress. On multisite, a main-site-only cron runner is insufficient. Confirm your host executes due events for every enabled site through origin or WP-CLI, especially with `DISABLE_WP_CRON`. For example, an operator can run `wp cron event run --due-now --url=<site-url>` for each enabled site on a monitored schedule. Do not rely on a potentially cached HTTP cron URL. Network activation means installation changes behavior across the whole network.

Manual purge actions start immediately. On WP Engine, the page-cache request starts first and Cloudflare waits in the queue as described below. URL batches contain at most 30 URLs. Persistent, non-autoloaded jobs deduplicate identical pending payloads and retain only failed batches; edits during an in-flight request leave trailing work. A successful HTTP attempt with trailing work is reported as **pending**, not complete. Unique database inserts prevent concurrent slot overwrites, comparison-and-swap updates protect generations, and bounded fresh database reads avoid stale object-cache slot selection. Each origin request reconciles missing cron events from the durable jobs, including after scheduler failures or concurrent WP-Cron option writes.

Transport errors, HTTP 408/429/5xx, malformed or unsuccessful 2xx responses retry; other 4xx errors are terminal. There are at most five attempts over 24 hours, with 60/120/240/480-second delays plus up to 30 seconds of jitter. `Retry-After` extends delays, with a shared-token cooldown for rate limiting or explicit server delay. An excessive delay expires the job without an early request. At most 100 jobs are pending per site; overflow is an explicit failure and never triggers a broader purge. A terminated attempt becomes eligible after a two-minute recovery lease. Site disabling or Zone ID changes cancel pending work. Jobs use current site credentials without storing tokens.

The last 100 network log records are retained; admin shows the newest 25. Details include site/zone, status, error codes, attempt, pending/failure outcome and sampled URLs. Each record retains at most 25 summaries with ten 300-character URL samples each and total URL counts. Queries, fragments, URL credentials, raw API error text and tokens are omitted. Site settings expose only that site's records. Concurrent network log writes remain best-effort; Clear Log does not cancel jobs.

### Paced maintenance purges (3.5.0)

WordPress plugin/theme/core update hooks, automatic-update completion, enabled external-cache-clear hooks and **Queue Maintenance Purge** now share one durable coordinator. They no longer immediately purge every enabled zone. The network queue uses Cloudflare hostname purges, one target per site's configured hostname; it never falls back to `purge_everything`. Ordinary saved-content URL jobs and explicit single-site manual purges retain their existing behavior and do not wait for maintenance.

Defaults: **120 seconds between origin dispatches**, **120 seconds between maintenance Cloudflare requests**, and **180 seconds of quiet after the last update activity**. Configure spacing from 60–3,600 seconds and quiet time from 60–1,800 seconds in the cache settings. These are conservative starting values, not measured capacity guarantees. With 17 distinct hosts, uninterrupted execution has at least 32 minutes between first and last origin dispatch, plus the quiet period, propagation wait and cron delays. Sites later in the queue may show old theme/plugin output longer.

For Dashboard → Updates → all plugins → themes → core, update-start hooks pause new maintenance stages and completion renews the quiet period. A newer update returns pending edge work to the origin stage; previously completed sites are queued once more, and untouched pending sites stay coalesced. Requests already sent cannot be recalled. No finite quiet period can infer a long human break: select **Hold for updates** on the Updates screen before starting, then **Updates finished** after core if you need a single session spanning arbitrary gaps. Hold survives failed/interrupted requests and remains until resumed. Automatic start detection also covers supported updater classes; external tools that bypass WordPress hooks are not detected.

An updater request that never reports completion blocks for up to 15 minutes from its last observed start hook plus the quiet period. Continuous activity can keep renewing the quiet period; this deliberately favors avoiding maintenance purges during updates over a fixed freshness deadline. Worker claims last five minutes; terminated work is retried after the lease. No delayed cron run drains overdue targets in a burst. Main-site cron must run, and normal origin requests reconcile missing coordinator events. Already-existing v3.4.4 broad jobs are not automatically migrated because their stored payloads do not identify whether they came from manual or maintenance actions.

The settings show per-site stage, next eligibility, and failure state. **Pause** stops further dispatch while retaining work. **Cancel pending** cancels current pending targets and remains stopped; later update hooks can accumulate new work, but only Resume permits dispatch. Resume does not recreate cancelled targets; use a new maintenance request if needed. **Retry failed** retries eligible failed targets through origin again and does not bypass a hold. Dispatch already in progress may complete after a control is clicked.

Origin/edge stages have at most five attempts each and a seven-day target lifetime. Transient provider failures and `Retry-After` hold the entire maintenance queue; exponential backoff is capped at one hour except a longer provider `Retry-After`. This is passive failure backoff, not origin load measurement: a successful asynchronous WP Engine dispatch does not prove origin health or completed invalidation. No probe or warm-up crawler is used.

Distinct hostnames sharing a Cloudflare zone remain separate origin and edge targets, using each site's current credentials. Shared hostnames (including disabled sibling records) and subdirectory mappings require scope review and are blocked from automated maintenance; there is no unsafe hostname/zone-wide fallback. Changed/disabled/removed scopes fail closed. Only the current WordPress network is inventoried. Queue storage is bounded to 1,000 site records; oversized inventories are rejected. No tokens are stored in queue state.

This queue cannot pace WP Engine dashboard/native cache clearing, WP Engine's own update callbacks, WordPress/other-plugin cache flushes, or Smart Plugin Manager. [WP Engine documents](https://wpengine.com/support/smart-plugin-manager/) that SPM independently clears Varnish, object and network caches after updates. This plugin does not disable those behaviors or flush object cache itself. Review those independent paths before a heavy-traffic update.

### WP Engine page cache (3.4.4)

When the installed WP Engine MU plugin exposes `WpeCommon::http_to_varnish`, both saved-content jobs and manual purges automatically send a WP Engine page-cache purge first. This is the transport used by WP Engine's own PHP page-cache purge function. No API credentials or network-dashboard navigation are needed.

The origin request targets the current site's hostname and affected URL paths, including old/new paths and query variants. A manual full purge clears that site's hostname/path scope in WP Engine; Cloudflare retains the selected zone-wide scope. On a subdirectory network, a full purge of the root site necessarily covers paths belonging to its subsites. External attachment/CDN hosts are not sent to WP Engine. This does not flush object cache, separate WP Engine network/CDN products, browser cache or a theme's generated assets.

WP Engine's transport is asynchronous: successful dispatch is not confirmation of completed invalidation. The durable job waits at least five seconds and until its next eligible cron pass before sending Cloudflare. This reduces immediate stale refills but cannot guarantee propagation has finished. Saved edits queue both stages; a manual purge starts the origin stage immediately and reports pending. Reported origin failures retry with the existing limits and block Cloudflare. A newer edit repeats the origin stage. `WPE_DISABLE_CACHE_PURGING` is respected, and our own cache-clear hooks cannot trigger recursive network purges. Sites without the WP Engine transport retain their existing Cloudflare flow.

See [WP Engine's cache documentation](https://wpengine.com/support/cache/) for the differences between page, object and network caches.

### Compatibility and operational limits

Canonical URL purges cannot prove freshness for all functional-query, header/cookie/device or Workers cache-key variants. Cloudflare also documents internal `PURGE` matching requirements for method-restricted cache rules. Existing rule expressions and public-page eligibility are unchanged. Review method restrictions and cache-key variants separately before changing live cache rules.

Direct SQL edits, asynchronous changes in later requests, term renames, parent-page slug changes affecting descendants, global permalink changes, same-priority shutdown handlers registered later and unenumerated template dependencies can need separate invalidation. Fatal termination before shutdown or database outages can prevent queue persistence. Cron processing still needs sufficient runtime for the backlog and 25-second HTTP timeouts. Reliable purging does not make personalized pages, server-side tracking or forms with expiring nonces safe to share from cache.

### Local verification and release

From the repository (tests are excluded from installable packages):

```sh
php -l acquire-cloudflare-cache-manager.php
php tests/wpengine-purge-regression.php
```

The [integration harness](tests/integration/README.md) provisions disposable real WordPress/MariaDB standalone and multisite sites with blocked outbound requests and fake Cloudflare responses. It exercises HTTP/REST saves, request shutdown, persisted cron, concurrency and optional real Redis Object Cache. It does not certify the production theme, host cache, PHP/database versions, browser login flow or live Cloudflare behavior.

The plugin header/class version is `3.5.0`; the matching release tag is `v3.5.0`. Release packages include only the plugin source, README, changelog and assets. Back up the installed plugin before updating; on multisite, replacement affects every site using that installation.
