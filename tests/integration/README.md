# Disposable real WordPress integration tests

Never install the harness on an existing WordPress site. `harness.php` deliberately acts as a local synthetic administrator and must never be deployed. The package allowlist excludes this entire directory.

## Requirements

- PHP with mysqli/curl and the normal WordPress extensions; Python 3.
- An extracted WordPress release (tested 7.1).
- A **dedicated disposable** MariaDB instance, initialized with `mariadb-install-db --no-defaults`, using a fresh `/tmp` data directory, private socket and `--skip-networking`. Do not point these scripts at a shared database server. No system service is installed or changed.
- Optional isolated Redis instance (`--port 0 --unixsocket <private-socket> --save '' --appendonly no`) and an extracted Redis Object Cache plugin. Tested MariaDB 11.4.13, PHP 8.3.8, Redis 8.10.1 and Redis Object Cache 2.8.0/Predis on macOS arm64.

For this run the database/Redis binaries came from Homebrew's public bottle metadata, downloads were checked against their advertised SHA256, and placeholder dylib paths were relocated only in the extracted `/tmp` copies. WordPress came from wordpress.org; Redis Object Cache came from downloads.wordpress.org. These runtimes are not repository or plugin dependencies.

## Provision

Choose a new root and database suffix. The provisioner rejects existing site directories and creates new databases without `IF NOT EXISTS`; an existing name fails. The supplied socket must live under `/tmp`. It does not delete databases.

```sh
python3 tests/integration/provision.py /tmp/acfcm-fresh /tmp/private-db/db.sock /tmp/wordpress _fresh
```

Start these as foreground processes in separate terminals (only if the ports are free):

```sh
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:18891 -t /tmp/acfcm-fresh/single
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:18892 -t /tmp/acfcm-fresh/multi
```

Then run:

```sh
python3 tests/integration/run.py /tmp/acfcm-fresh single
python3 tests/integration/run.py /tmp/acfcm-fresh multi
python3 tests/integration/timed.py /tmp/acfcm-fresh single
```

The timed test takes roughly 60–90 seconds and does not edit the retry deadline. Other tests advance fixture deadlines to avoid long waits, but call real `wp-cron.php` to execute persisted events. Do not run the timed test concurrently with another test against the same site; the HTTP mock state is shared per installation.

For persistent cache coverage, enable the drop-in and repeat the two full suites:

```sh
python3 tests/integration/redis.py /tmp/acfcm-fresh /tmp/redis-cache /tmp/private-redis/redis.sock
```

Expect `persistent_cache: true` and `cache_connected: true` in the result, not just a passing HTTP request. This uses the actual Redis Object Cache drop-in, not an object-cache test double.

## What is real and what is intercepted

Real: WordPress installation and network schema, SQL database, HTTP worker processes, WordPress post/term/meta hooks, REST controllers, shutdown, cron option persistence, job compare-and-swap, optional Redis and its cache invalidations. Two multisite sites use the same imported post ID with separate URL paths/zones/tokens.

Intercepted: all Cloudflare responses, including delays, failures and a terminated HTTP attempt. Every other outbound WordPress HTTP request returns a local-only error; mail is short-circuited. Only synthetic settings/content/fake tokens are written. `DISABLE_WP_CRON` is intentionally set; tests explicitly invoke cron to demonstrate the origin/cron dependency. Expected core update-check warnings can appear because outbound HTTP is blocked.

The loopback harness checks a test-only header and sets a synthetic administrator before `rest_do_request`; it tests editor-equivalent REST controller timing, not browser login/cookie/nonce authentication. No production assets or plugins are imported.

`reset` removes only plugin test jobs/events/logs and synthetic cooldowns in the current disposable site. Content remains, so repeated runs use unique slugs/import IDs. `advance` and `schedule-now` change fixture timestamps only. An actual scheduled publication still runs through WordPress's `publish_future_post` hook.

## Cleanup

Stop only the PHP server processes/workers, MariaDB and Redis processes you started (record their PIDs). Once stopped, remove the fresh disposable directories if desired. Do not use broad `killall`, global cache flushes, shared database drops or system-service commands. The local verification report records process shutdown separately from test results.
