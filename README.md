# Acquire Cloudflare Cache Manager

Cloudflare cache purging plugin for standalone WordPress sites and WordPress multisite networks.

## What it does

- Works on standalone WordPress installs and is network-activated only on multisite.
- Uses existing per-subsite `cloudflare_zone_id` values from older plugin versions.
- Auto-engages subsites in `Auto` mode when a Zone ID already exists.
- Allows each subsite to be `Auto`, `Enabled`, or `Disabled`.
- Adds subsite toolbar purge options for enabled sites.
- Automatically purges related URLs on public content updates.
- Previews and explicitly migrates verified legacy cache rules to coordinated public-page request/response rules; recognizes existing optimized policies without rewriting them.
- Normalizes allowlisted tracking-only HTML query strings while bypassing unknown/functional queries; entitlement failures stop without dropping safety settings.
- Optionally makes selected standalone sites or multisite subsite hostnames eligible for Cloudflare Cache Reserve.
- Optionally enables Cloudflare Tiered Cache with Smart topology for selected Cloudflare zones when installing recommended cache rules.
- Verifies deployed Cloudflare defenses without rewriting them; explicitly onboards a Free-plan defense baseline on new zones when ownership and quota checks pass.
- Queues paced per-site maintenance after selected WordPress updates when enabled.
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

For purge-only use, the token can be limited to cache purge access. To use the recommended cache rule setup action, the token also needs Cloudflare's `Zone > Cache Rules > Edit`, `Account Rulesets > Edit`, and `Account Filter Lists > Edit` permissions for the relevant zone/account. Enabling Tiered Cache with Smart topology also needs permission to edit zone settings, shown in Cloudflare as `Zone > Zone Settings > Edit` or `Zone > Cache Settings > Edit` depending on the token UI. Defense verification requires access to read the zone custom and rate rulesets; explicit onboarding also requires `Zone > WAF > Edit`. Purge-only tokens cannot onboard defenses.

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

## Public-page cache policy (3.7.1)

Upgrading the plugin does **not** migrate Cloudflare cache rules or install a runtime helper. Choose **Install cache rules** on the subsite settings page or in the **Actions** column in **Sites on this network**. One click saves a backup, installs/verifies the runtime guard and replaces existing cache rules with the standard policy. There is no preview or agreement checkbox. **Install security rules** independently verifies/adds the security baseline. Installation runs in the browser with visible progress; keep the page open. With JavaScript unavailable, the form starts a saved installation that can be continued with Resume.

This profile is for public informational sites with a canonical HTTPS domain at its root, one WordPress site per Cloudflare zone, and automatic content purging enabled. Subdirectories, shared zones and personalized applications require individual review. Unfamiliar cache rules are included in the backed-up replacement.

- Three request rules keep versioned static assets separate, cache eligible anonymous public permalink HTML for seven days, and bypass dynamic requests. Only an explicit tracking-parameter allowlist shares the HTML cache key. GET, HEAD and PURGE use the same key behavior. Functional or unknown query parameters bypass. The browser URL is unchanged; an edge HIT does not run origin-side tracking.
- Three response rules give Gravity Forms HTML a 24-hour edge TTL, preserve private/no-store/no-cache/max-age=0/non-200 responses, and retain the reviewed bot-cookie exception. Application cookies prevent caching. Bot-cookie stripping is limited to otherwise-safe form HTML whose outgoing cookies are all `__cf_bm`.
- A persistent MU-plugin guards password-protected pages (including unlocked ones), authenticated/preview responses and Gravity Forms markers. It is installed only on explicit Apply. Reviewed older Acquire helpers retain ownership of their original scopes, avoiding duplicate buffers. Unknown helper edits stop setup. The main plugin refuses deactivation while a managed scope remains; manually deleting files or changing WordPress options outside this UI can still bypass those protections.
- Install cache rules replaces both request/response cache phases, including custom cache exceptions. No exact template match is required. Matching standard rules are retained without rewriting. Security phases remain separate.
- The installer saves original rules before replacement and stops on concurrent provider changes. Download the backup from the same screen; rollback restores the original definitions and order.

Installation saves a full backup before any provider write, installs/verifies the guard, adds a temporary host bypass, changes one rule per request, verifies readback, and removes the bypass last. A browser interruption can be resumed explicitly. Guard installation uses a verified same-directory rename under a file lock, without requiring hard links. Guard failures pause the migration before provider writes; after resolving installation, Resume retries the guard and continues the saved plan. Drift stops writes; uncertain responses retain a durable intent to reconcile before retry. Cloudflare writes are not transactional: allow a quiet administration window and review any partial state. The temporary bypass can increase origin traffic while a migration is paused. No purge is part of migration or rollback.

**Recovery:** download the journal, review the visible status, and use Resume or Preview rollback. Rollback restores the saved rule definitions/order; recreated rules have new IDs and new phase containers remain empty. Existing optimized adoption is not a request to undo the preexisting policy. An unchanged repeat adoption retains the original migration's rollback point. Locks left by a terminated PHP worker require an administrator to first verify no worker remains active. Keep the persistent guard installed while its edge policy exists.

**Optional settings:** Cache Reserve eligibility still needs separately enabled Cloudflare storage/entitlement. Smart Tiered Cache reads and backs up both zone settings before changing them; a failure or drift remains visible without a safety fallback. Unchecking the option does not switch zone settings off. Security installation is a separate button and preserves existing security policies. Cache rollback leaves these zone settings/security additions in place; their originals are available in the backup for separate review. Defense-only controls remain available.

**Bounded storage:** purge history remains capped at 100 entries, pending purge jobs at 100 per site and maintenance targets at 1,000. Cache migration snapshots are limited to 256 KB per read, previews to 1 MB and each zone journal to 2 MB with at most one prior generation. They are non-autoloaded options, reused for each zone. No plugin disk log is created. The installed MU guard is a small PHP file, not a log. Retired zones can leave one bounded journal each for recovery.

**Admin layout:** overview and routine page refresh appear first. Maintenance and migration failures remain visible. Connection settings, security actions and update checks use native disclosure sections. A visible **Clear WP Engine + Cloudflare cache** button clears this domain’s WP Engine page cache and the configured Cloudflare zone in one action. Network and site permissions, nonces, blank secret inputs, token inheritance, update selections, timing controls, Hold/Resume/Cancel/Retry, logs and toolbar actions are retained.

## One-click security installation (3.7.4)

**Install cache** and **Install security rules** appear in each network site's Actions cell and on subsite settings. The security button automatically validates the current zone, saves a bounded non-autoloaded backup, and installs all recommended protections in one action. There is no preview, acknowledgment or approval step. No security changes run on plugin upgrades, content edits or cache purges.

The complete set contains five custom rules and one rate rule:

- WordPress exploit-probe blocking.
- XML-RPC blocking (except verified bots), matching the legacy recommendation. Sites that need XML-RPC integrations should account for this before using the complete installer.
- Managed challenges for legal-page query strings.
- Sensitive-file probe blocking.
- Submission/session exceptions that skip only rate limiting.
- Public-page rate blocking: 30 matching requests per 10 seconds per IP and Cloudflare data center, with a 10-second block.

Matching existing rules keep their IDs and definitions. Sites with the previous three-rule baseline receive the three missing legacy protections. The public-page limiter covers legal pages as part of the broader policy; the older separate legal-page limiter is not added as a second rate rule. Custom conflicts or insufficient capacity produce a specific error without deleting existing protections. The installer conservatively budgets five custom rules and one rate rule. Historically excluded zones use current active/unpaused status checks automatically, with no separate approval screen.

Both phases are preflighted before writing. A per-zone WordPress lock prevents overlapping installers in the same installation, across its networks. Each addition uses a create-only API request with stable references, fresh phase reads and verified readback. There is no whole-ruleset PUT, rule PATCH, DELETE, automatic retry or rollback. Concurrent external changes and partial/uncertain API failures stop further writes with an actionable notice. Cloudflare does not provide a transaction spanning these calls: a concurrent external edit in the read/write window or a partial request can leave an addition applied. Inspect both phases before retrying; never blindly restore an old snapshot. Separate WordPress installations do not share the local lock.

A crashed installer deliberately leaves `acfcm_defense_lock_<zone-id>` on the main site of the main network (current site on standalone). Only after confirming that no installer is active, an administrator can remove that option with WP-CLI on that site and retry. No new disk logs or growing policy history are created.

API references: [add a rule without replacing existing rules](https://developers.cloudflare.com/ruleset-engine/rulesets-api/add-rule/), [rate limiting capabilities](https://developers.cloudflare.com/waf/rate-limiting-rules/), [custom rules](https://developers.cloudflare.com/waf/custom-rules/).

### Safe upgrade and canary

1. Back up the installed plugin and read current queue/settings and Cloudflare rules without changing them. Publishing the release does not install it on WordPress or change Cloudflare policies.
2. For a controlled canary from v3.5.0, use **Hold for updates** before replacing the plugin if this update should not start a maintenance batch. Review pending work before resuming. Ordinary future updates do not require Hold; it is an optional operational pause. From v3.4.4, temporarily disable its automatic update/external-clear purge triggers and review pending broad jobs before upgrading.
3. Update one installation first. Verify the version, settings, queue and editor; do not click combined cache installation merely to test compatibility. Upgrade itself adds no firewall writes or immediate broad purge; existing enabled update hooks can still enqueue the existing paced maintenance batch.
4. A separately approved defense-only canary on one already-deployed zone should perform GET verification only and leave both rulesets identical. Choose an augmented zone and then the two retained-policy exceptions before considering a network-wide verification. No cache purge is needed.
5. New-zone onboarding is a separate explicit choice. Review its existing rules and quota first. Test public/tracking pages, form submission, authenticated editing, previews and media after an approved onboarding. Local mocks and saved traces are not end-to-end production form tests.
6. Resume only the maintenance work intended to run. Do not revert to an older plugin and rerun its legacy hardening installer: that reintroduces destructive description matching. Rolling back this plugin code does not roll back Cloudflare rules.

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

WP Engine's transport is asynchronous: successful dispatch is not confirmation of completed invalidation. For automatic edits and individual-URL purges, the durable job waits at least five seconds and until its next eligible cron pass before sending Cloudflare. This reduces immediate stale refills but cannot guarantee propagation has finished. Saved edits queue both stages; an individual-URL purge starts the origin stage immediately and reports pending. The combined **Clear WP Engine + Cloudflare cache** button dispatches origin first and Cloudflare immediately afterward in the same request; no cron wait is needed on success. Because origin invalidation is asynchronous, this immediate action can refill stale content before origin propagation finishes. Existing pending jobs, provider failures and cooldowns still use the durable retry queue. Reported origin failures retry with the existing limits and block Cloudflare. A newer edit repeats the origin stage. `WPE_DISABLE_CACHE_PURGING` is respected, and our own cache-clear hooks cannot trigger recursive network purges. Sites without the WP Engine transport retain their existing Cloudflare flow.

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

The plugin version is `3.7.2` and its release tag is `v3.7.2`. Release packages include only the plugin source, README, changelog and assets. Back up the installed plugin before updating; on multisite, replacement affects every site using that installation.

### Formerly excluded security zones (3.7.2)

Current zone identity/status and scope are checked automatically by the single Install security rules action. Zone Read permission is required; cache journals remain unchanged.

### Site-only WordPress object-cache clearing

The explicit **Clear WP Engine + Cloudflare cache** site action first invalidates known WordPress cache keys for that subsite: autoloaded and individual options, existing posts/metadata, terms/metadata, comments/metadata, taxonomy relationships, and core query versions. This refreshes stale WordPress values after direct database search-replace. It does not flush the WP Engine customer-wide object-cache generation, global/user/network groups, or other subsites. Opaque third-party keys and individually cached deleted records cannot be safely enumerated and are not included. Network maintenance purges retain their existing behavior.
