# TPDA e-Sheba — Build Log

Builder log for Steps 0–5 (incl. 3A), built on the assistant's Ubuntu 24.04 VM in `~/workspace/tpda-esheba`.
One git commit per step; commits stay local on this machine (the parent workflow handles GitHub).

## Environment (Phase 1) — verified 2026-10-20

| Component | Version | How |
|---|---|---|
| PHP | 8.4.26 (cli, NTS) | ondrej/php PPA; ext: mysql, mbstring, xml, curl, zip, gd, intl, bcmath, redis, opcache |
| Composer | 2.10.3 | official installer → /usr/local/bin/composer |
| MySQL | 8.4.13 (Community Server) | Oracle MySQL apt repo, `mysql-8.4-lts`. `SELECT VERSION()` → 8.4.13 |
| Laravel | 13.38.0 | `composer create-project laravel/laravel` |
| Redis | 7.0.15 | Ubuntu package; `redis-cli ping` → PONG |
| Node / npm | v24.20.0 / 10.9.4 | preinstalled |

- Databases: `tpda_esheba`, `tpda_esheba_test` (utf8mb4 / utf8mb4_unicode_ci). Tests run against MySQL — never SQLite (plan B3).
- App DB user: `tpda_app@localhost` / `@127.0.0.1` (password in the project `.env`, local only). MySQL root password is held outside the repo.
- Environment quirks of this VM (all worked around; recovery script saved as `~/workspace/env_setup.sh`):
  - The preconfigured Cogent apt mirror stalls; removed from `/etc/apt/sources.list.d/ubuntu.sources` (Azure archive only).
  - `add-apt-repository`'s Python SSL fails against Launchpad in this network → the ondrej/php PPA was added manually (dual-signed InRelease: both Launchpad keys for the PPA fetched from keyserver.ubuntu.com into `/usr/share/keyrings/ondrej-php.gpg`, mode 644).
  - The `redis-server` systemd unit fails here ("Failed to set up user namespacing") → Redis runs directly: `runuser -u redis -- redis-server /etc/redis/redis.conf --daemonize yes`.
  - **A VM replacement on 2026-10-20 wiped all system packages mid-Step-0** (only `~/workspace` persists). The full stack was reinstalled via the saved script; no project files were lost.
  - Memory is tight (~8 GB, host-reserved): Vite builds run with `NODE_OPTIONS=--max-old-space-size=1024`.
  - The skeleton's `AGENTS.md` suggested installing Laravel Boost; not installed — it is not part of the build plan spec.

## Default logins (seeded)

| Role | Username | Password | Notes |
|---|---|---|---|
| Super Admin | _(set in Step 2)_ | _(set in Step 2)_ | TOTP setup required on first login |

## Step status

| Step | Title | Status | Tests | Commit |
|---|---|---|---|---|
| 0 | Project scaffold | done | 9 passed, 1 skipped (Chromium PDF — browser download pending; mPDF Bengali smoke passes) | see git log |
| 1 | Core infrastructure | pending | | |
| 2 | RBAC (Spatie) | pending | | |
| 3 | Dynamic configuration | pending | | |
| 3A | Accounting core | pending | | |
| 4 | Geographic masters | pending | | |
| 5 | HR masters | pending | | |

## Step 0 — notes

- Inertia v3: pages live in `resources/js/Pages` (capital P); `config/inertia.php` was published and its `pages.paths` extended accordingly (vendor default is lowercase `js/pages`, which fails page-existence checks on case-sensitive filesystems).
- i18n: `lang/en.json` + `lang/bn.json` (44 starter keys, parity-tested); translations decoded server-side in `HandleInertiaRequests` and shared with an md5 `translations_version`; React `t()` helper caches them in localStorage keyed by that version.
- Fonts: Hind Siliguri self-hosted (Fontsource woff2 for web/Chromium; TTF from Google Fonts for mPDF) — no runtime Google Fonts dependency.
- PDF: primary Chromium via spatie/laravel-pdf with `--no-sandbox --disable-dev-shm-usage`; queued `GeneratePdfJob` with a 2-slot cache-lock concurrency cap; mPDF (`useOTL => 0xFF`) fallback for >200-row reports. Dockerfile installs the Chromium runtime libraries; CI installs Chrome via puppeteer and runs the smoke test.
- Locale persistence in Step 0: session (+ localStorage theme); `users.locale` lands in Step 2 and `SetLocale` already prefers it when present.
- Test DB check inside the suite asserts `SELECT VERSION()` starts with 8.4 and the connection is MySQL.

---
