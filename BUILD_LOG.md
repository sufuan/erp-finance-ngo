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
| Super Admin | `admin` | `Tpda#Admin2026!` | Must change password + TOTP enrollment on first login |

## Step status

| Step | Title | Status | Tests | Commit |
|---|---|---|---|---|
| 0 | Project scaffold | done | 9 passed, 1 skipped (Chromium PDF — download stalls in this VM's network; mPDF Bengali smoke passes; CI installs Chrome) | bb0fe05 |
| 1 | Core infrastructure | done | 19 passed, 1 skipped (Chromium) | 8d0d92a |
| 2 | RBAC (Spatie) | done | 36 passed, 1 skipped (Chromium) | 910ed01 |
| 3 | Dynamic configuration | done | 41 passed, 1 skipped (Chromium) | 6320f3e |
| 3A | Accounting core | done | 53 passed, 1 skipped (Chromium) | 1d616ce |
| 4 | Geographic masters | done | 58 passed, 1 skipped (Chromium) | 6a8eac9 |
| 5 | HR masters | done | 63 passed, 1 skipped (Chromium) | bb54135 |
| 6 | Employees & directors | done | 72 passed, 1 skipped (Chromium) | (this run) |

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

## Step 3 — notes

Status: **done**. Tests: 41 passed, 1 skipped (Chromium PDF) — full suite green.

- Seeded exactly per plan: TPDA profile (EN/BN names, NGO type, reg. সিরাজ/৫৮৫/সমাজসেবা/২০০০, mobile app "TPDA e-Sheba", contact Md. Mostafa) + 27 settings incl. `loan.default_profit_rate`=15, MRA cap 24, `llp.rates` {1,5,25,75,100} on `net_outstanding`, allocation order [penalty, profit, principal], auth threshold 50000, GPS 60s/90d, max offline 7 days. Architecture §15 extras also seeded: `deposit.closing_charge`, `sms.gateway`/`sms.credentials`/`sms.enabled`, `collection.sheet_types`, `collection.allow_backdate`.
- Plan-unspecified money defaults (`loan.fee_defaults`, `deposit.default_profit_rates`, closing charge) seed at **0** — TPDA's real figures are entered in the UI (no invented numbers).
- `SettingsService`: Redis-cached `get`, cache-busting `set` (tested), org profile cached too; web `<title>`, sidebar app name, and `SetLocale` default all read from settings now.
- General Settings UI: 6 tabs (Organization incl. logo upload, Application, Loan & Profit, Deposit, SMS & Notifications, GPS & Tracking), guarded by `settings.general`, each change audited (`settings.updated` / `organization.updated`).
- Lang files now 321 keys in both en/bn.

## Step 3A — notes

Status: **done**. Tests: 53 passed, 1 skipped (Chromium PDF) — full suite green. Manual acceptance verified via tinker: receipt posted (batch `102000001`, auto-authorized), trial balance balanced, reconcile clean — the voucher remains in the dev DB as evidence.

- Tables: `ledgers` (35-account NGO CoA, EN+BN), `vouchers`, `voucher_lines` (account tags are plain columns; CHECK constraint blocks setting both; FKs land in Steps 10/17), `gl_mappings` (31 keys), `account_transactions` (ENUM incl. `share`; unique idempotency key), `cash_ledgers`, `fiscal_years` (FY 2026-27 open per branch), `voucher_types` (Receipt auto-auth; Payment/Journal/Contra require auth).
- `PostingService::post()` is the only money path: brick/math balance validation, gl_mappings resolution (test asserts no hard-coded ledger codes in the service), business-day guard, locks ONLY on `cash_ledgers` / latest `account_transactions` rows — never `ledgers`; GL balances derived via `ledgerBalance()` from `voucher_lines` sums.
- Authorization timing per plan: balances move at posting; Pending only when the type requires it or total ≥ `accounts.auth_amount_threshold` (50,000). SoD via `AuthorizationService`; break-glass (Super Admin + permission + mandatory reason) is audited as `voucher.breakglass_authorized`.
- Idempotency: explicit `batch_number` re-post is a no-op + the sub-ledger unique index (both tested). Row-lock serialization proven with a two-connection NOWAIT test.
- `accounts:reconcile` (nightly 02:30): cash cache vs sub-ledger, GL controls vs sub-ledger (deposit controls in aggregate), per-branch trial balance; mismatches → `audit_logs`.
- Note: brick/math installed is v1.0 — `RoundingMode` is an enum (`HalfUp`).

## Step 4 — notes

Status: **done**. Tests: 58 passed, 1 skipped (Chromium PDF) — full suite green.

- 7 geo tables + generic `GeoController` CRUD (search, 10/25/50/100 pagination, create/edit/delete) driving one parameterized page; parent selects cascade (division→district→upazila→union→village for Working Areas), desktop table + mobile cards per the step's acceptance.
- Server-side chain consistency: village `upazila_id` is derived from its union; working-area chain mismatches are rejected with 422; parents with children cannot be deleted.
- `branches` gained nullable `country_id/division_id/district_id/upazila_id/union_id` FKs (strings kept as fallback).
- Seed: Bangladesh + all 8 divisions, Rajshahi's 8 districts, Sirajganj's 9 upazilas, 6 Shahjadpur unions, Talgachi village + "Talgachi Working Area".
- Note: the string→FK branch backfill runs in `GeoSeeder`, not in the migration — geo rows are seed data and don't exist at migrate time. Branch 102 is pinned to the Talgachi chain; branches with a district string get matched FKs automatically.

## Step 5 — notes

Status: **done**. Tests: 63 passed, 1 skipped (Chromium PDF) — full suite green; production build verified.

- Tables: `departments` (branch FK; `incharge_id` plain — FK lands in Step 6), `designations`, `employee_types`, `shifts` (all time/grace/overtime/bonus fields from the plan).
- One field-config-driven controller + page serves all four masters under `/hrm/*`, gated by `hrm.masters`; validation requires shift name/start/end/grace (per acceptance); every field editable, lists show all fields, mobile-responsive.
- Seeds: 5 head-office + 2 branch departments, 7 designations (Executive Director → Driver), 4 employee types (Permanent/Probation/Contractual/Part-time), "General Shift" 09:00–17:00, grace 10 min, break 13:00–14:00, overtime 17:00–20:00, 26 duty days, 3 allowed late days.
- Lang files now 360 keys in both en/bn; UI t() key coverage sweep reports no missing keys.

---

# Steps 6–20 run (2026-10-21)

Pre-step note: the 7 files from `github-missing-files.zip` (ci.yml, favicon, Hind Siliguri fonts) were already tracked in the local repo since Step 0 — the zip only ever mattered for the GitHub push channel. Extraction verified byte-identical, working tree clean; nothing to commit locally.

## Step 6 — notes

Status: **done**. Tests: 72 passed, 1 skipped (Chromium PDF) — full suite green.

- Tables: `employees` (code = one global 6-digit sequence via NumberingService scoped on the head-office branch row, so codes stay unique org-wide), `directors` (same personal/bank shape, `director_status`, no branch). NID/passport/TIN use the `encrypted` cast; `nid_hash`/`passport_hash` (SHA-256) are maintained by a model `saving` hook and are unique.
- Forward FKs enforced here as planned: `branches.manager_id`, `users.employee_id`, `departments.incharge_id` → employees; `employees.manager_id` self-FK.
- `IdentityCheckService`: NID/mobile/passport uniqueness enforced across employees + directors (+ members automatically once that table exists in Step 8 — it checks `Schema::hasTable`).
- Pages: Settings → Employees / Directors (one config-driven `RegisterController` + page): personal + bank sections, photo upload via UploadService (GD re-encode), search by name/mobile/NID/code, desktop full-column table with photo, mobile stacked cards. Gated by `hrm.employees`.
- Seeds: Md. Mostafa (Executive Director, HO) + Talgachi branch manager (wired into `branches.manager_id` and the Microfinance Operations department `incharge_id`) + 2 field officers.
- Step 5's `HrmMastersTest` used `incharge_id = 42` with the comment "plain column until Step 6" — updated to a real employee id now that the FK exists, exactly as that test anticipated.
- Lang files now 403 keys in both en/bn.

## Step 7 — notes

Status: **done**. Tests: 82 passed, 1 skipped (Chromium PDF) — full suite green.

- Environment incident: the VM was replaced a second time mid-step (PHP/MySQL/Redis binaries vanished; `~/workspace` intact, Step 6 commit safe). The platform's os-intent replay restored parts (Ubuntu MySQL 8.0, Redis) but not the pinned stack; `env_setup.sh` then rebuilt everything — PHP 8.4.26, MySQL **8.4.13**, Redis PONG, both DBs recreated. Note: a backgrounded `env_setup.sh | tail` masked the script's real exit code earlier in the incident; the script itself is fine and idempotent. `log_bin_trust_function_creators` re-set globally + persisted in `/etc/mysql/mysql.conf.d/zz-tpda.cnf`.
- Tables: `samities` (code = one global 4-digit sequence via NumberingService head-office scope, geo lat/lng, day/center-type enums), `samity_day_changes` / `samity_officer_changes` / `samity_manager_changes`, `holidays`, `notices`. `samity_user.samity_id` FK enforced here as planned.
- `SamityScope` (global scope, applied via attribute on Samity; reads the pivot table directly to avoid scope recursion) restricts Field Officers to assigned samities at query level; `SamityAccessService` guards single-samity routes with 403. Both unit- and web-tested (list scoping + change-page 403 for unassigned samity).
- Pages: Samity Opening (form + list, browser-geolocation "Use My Location" + OpenStreetMap link), Day/Officer/Manager change (current readonly → new value + effective date + comments, history tables), Holidays (single/range, max 31 days, existing dates skipped), Notices (latest renders in the topbar via shared Inertia prop, in its chosen color), Merge (same-branch only; members moved with a per-member audit entry; source closed as `Merged`; active-loan block unless manager-approved), Split (new-samity form pre-filled from source, member pick-list). Gated `settings.view` (read) / `settings.general` (write).
- Merge/split loan-block checks and member moves already operate on `members`/`loan_accounts` when those tables exist (`Schema::hasTable` guards) — they become fully live in Steps 8/17 with no code change.
- Step 2's `RbacTest` used placeholder samity ids `11, 12`; updated to the real seeded samity ids now that the FK is live (same anticipated forward-fix pattern as Step 6).
- Seeds: Talgachi Samity (Wed, officer Rofiqul) + Talgachi Mohila Samity (Sun, Female, officer Sharmin).
- Lang files now 467 keys in both en/bn.
