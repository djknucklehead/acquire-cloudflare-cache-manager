# AGENTS.md

## Project overview

This repository contains a WordPress plugin named Acquire Cloudflare Cache Manager. The primary source file is `acquire-cloudflare-cache-manager.php`; supporting release notes live in `CHANGELOG.md`, and public usage notes live in `README.md`.

## Development guidelines

- Keep the plugin header `Version` and `Acquire_Cloudflare_Cache_Manager::VERSION` in sync.
- Update `CHANGELOG.md` for user-facing behavior changes.
- Preserve compatibility with standalone WordPress and multisite/network-activated installs.
- Use WordPress capability checks, nonces, escaping, sanitization, and HTTP APIs for admin actions and external requests.
- Do not hard-code Cloudflare API tokens, GitHub tokens, or site-specific secrets.
- Keep the baked-in GitHub updater repository value aligned with the public release repo unless the user explicitly asks to change it.

## Verification

- Run `php -l acquire-cloudflare-cache-manager.php` after PHP changes.
- For release changes, confirm the release tag format is `vX.Y.Z` and matches the plugin version.
- For GitHub Actions changes, inspect the workflow YAML carefully because this repo's release zip is built by GitHub.

## Review guidelines

- Treat missing capability checks, missing nonce verification, token exposure, unsafe Cloudflare/GitHub requests, version mismatches, and broken updater/package paths as high-priority findings.
- Check both standalone and multisite behavior when admin settings, purge behavior, or update hooks change.
