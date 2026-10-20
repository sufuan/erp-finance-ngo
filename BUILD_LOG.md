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
| 0 | Project scaffold | done | 9 passed, 1 skipped (Chromium PDF — download stalls in this VM's network; mPDF Bengali smoke passes; CI installs Chrome) | bb0fe05 |
| 1 | Core infrastructure | done | 19 passed, 1 skipped (Chromium) | 8d0d92a |
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

## Step 1 — notes

- Tables: `branches`, `business_days`, `sequences`, `audit_logs` (+ immutability triggers). Seeded branches: 101 Head Office, 102 Talgachi Branch (parent 101), each with an open business day.
- **audit_logs append-only — implementation note:** the plan asked for an INSERT+SELECT-only grant for the app DB user. MySQL cannot carve a table-level exception out of a database-wide grant (error 1147, even with `partial_revokes`), so immutability is enforced by BEFORE UPDATE/DELETE triggers created in a migration (bind every user; recreated by `migrate:fresh`), plus the AuditLog model guard, plus the hash chain. `db:apply-grants` remains as a best-effort grant layer + documentation. Trigger creation needs `log_bin_trust_function_creators = ON` (set + persisted on this VM; CI does the same before migrating).
- MySQL root originally used `auth_socket`; switched to `caching_sha2_password` so TCP admin (PDO) works.
- Uploads: private disk only, `FileController` requires auth (guests get 403), images re-encoded via GD (payload strip verified by test), `.xls` rejected.
- Backup: `db:backup` verified locally (dump → disk); scheduler entry at 02:00; Supervisor config + cron/TLS notes in `docs/deploy/`.
- Tests use `DatabaseTransactions` against the pre-migrated + seeded test DB (CI mirrors this: migrate --seed, then test).

## Step 2 — notes

Status: **done**. Tests: 36 passed, 1 skipped (Chromium PDF) — full suite green.

### Seeded Super Admin (first login)
- Username: `admin`
- Password: `Tpda#Admin2026!` (seeded; `must_change_password` forces a change, then TOTP enrollment is mandatory for this role)

### Built
- Spatie RBAC: 139 permissions from `App\Support\PermissionCatalog` (16 modules), 6 system roles (super-admin 139, branch-manager all-but-3, accountant, field-officer, hr-officer, auditor=read-only set). Roles/permissions extended with label/description/is_system + module columns; every permission has `perm.<name>` labels in both lang files (263 keys total, parity-tested).
- Users reshaped: username login (email nullable), argon2id (`config/hashing.php`), lockout 5 attempts → 15 min, `login_audits`, last_login tracking, idle session 30 min (`SESSION_LIFETIME`).
- TOTP (pragmarx/google2fa + Bacon QR SVG): mandatory for super-admin / branch-manager / accountant — enrollment on first login, challenge on later logins; secret stored with the `encrypted` cast. Session flag `totp_passed_at` gates the app via `EnsureTotpVerified`; `EnsurePasswordChanged` runs before it.
- Admin UI: Users (create/edit, roles + branch assignment with include_children, samity IDs, reset password with one-time temp password flash, activate/deactivate with last-super-admin + self guards, login history), Roles (permission matrix grouped by module, per-module select-all, search), Permissions (read-only catalog).
- Segregation of duties: `AuthorizationService::assertDifferentMakerChecker` (used from Step 3A onward).
- Branch scoping: `BranchAccessService` — switcher + active branch restricted to assignments (super-admin = all); switching to an unassigned branch returns 403.
- Guards: system roles cannot be deleted or renamed; roles with users cannot be deleted; users cannot remove their own `settings.users-manage`.
