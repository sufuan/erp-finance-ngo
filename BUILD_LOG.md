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
| 6 | Employees & directors | done | 72 passed, 1 skipped (Chromium) | 337ae86 |
| 7 | Samities, holidays, notices | done | 82 passed, 1 skipped (Chromium) | (this run) |

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

## Step 8 — notes

Status: **done**. Tests: 89 passed, 1 skipped (Chromium PDF) — full suite green. 58 tables, 529 lang keys in both en/bn.

- Tables: `members` (member_no unique via NumberingService per-branch display id, branch derived from the samity, encrypted NID/passport/TIN + unique SHA-256 hashes, `share_amount` DECIMAL(18,2) = canonical share capital), `member_users` (portal login; username = mobile, fallback `M<member_no>`; argon2id; inactive until activated; must-change-password), `nominees` (age derived from dob — deliberately no age column), `member_closings` (share snapshot + `share_disposition` Refunded/Forfeited, closed_by/at, cancelled_by/at), `member_visits` (lat/lng + check_in_at), `member_documents`, `member_login_logs`, `member_transfers`.
- Admission: tabbed Basic / Other / Pictures form with member-profile side panel; success modal "Save Successfully! ID is: N" via a one-time flash prop; nominee row + portal user created in the same transaction; photos via UploadService (GD re-encode, private disk). KYC uniqueness (NID/mobile/passport) enforced through Step 6's `IdentityCheckService`, which now spans employees + directors + members.
- List: samity/name/phone/member-no/NID-hash/status/gender/open-date filters + advance filter, the reference's 20-column table with expandable rows, mobile cards; row actions: 360, Edit, Documents, Admission Form, Add Visit, Check In, Update Location (browser geolocation when available).
- Transfer: same-samity rejected; active-loan block unless manager-approved; writes `member_transfers` + audit entry. Closing: already-closed / active-loan / deposit-balance blocks — the loan and deposit checks are `Schema::hasTable`-guarded and go fully live in Steps 17/10; share disposition is recorded on the closing row now, and the actual money posting lands with Step 11's share flow (no fake money movement at this step). Cancel-closing restores the member to Active.
- Forward-fixes (same anticipated pattern as Steps 6/7): `MembersTest` uses a fresh factory super-admin with `must_change_password = false` (the seeded admin still owes its first-login change); `CoreInfrastructureTest`'s numbering assertions made seed-agnostic (relative increments + cross-pair independence) because MemberSeeder now legitimately consumes Talgachi-branch `member` numbers.
- Seeds: 3 members (Rokeya Begum, Hanif Sheikh in Talgachi Samity; Fatema Khatun in Talgachi Mohila Samity), each with a nominee where given and an inactive portal user.

## Step 9A — notes

Status: **done**. Tests: 93 passed, 1 skipped (Chromium PDF) — full suite green. 59 tables, 562 lang keys in both en/bn.

- Table: `member_import_batches` (creator, page-level default samity, source excel|grid, status Preview|Queued|Processing|Completed, totals, staged `rows` JSON with per-row dry-run verdicts, `failures` JSON for the error report). Settings → Member Migrations, gated `settings.view` (read) / `settings.general` (write), same pattern as Step 7.
- Flow exactly per plan: dry-run parses + validates ALL rows and saves nothing (per-row OK/error flags in the preview grid); confirm dispatches `ImportMembersJob` — chunks of 100, each row re-validated and admitted in its own transaction via the new shared `MemberAdmissionService` (extracted from Step 8's controller `store()`, which now delegates to it — same member + nominee + portal-user creation, so imported members are indistinguishable from form-admitted ones); failures are collected per row and never block later rows; error report downloads as xlsx (Row / Member ID / Name / Mobile / Reason).
- Row rules: name, samity (by id/code/name, or the page default), gender, father's/husband's name, mobile (BD regex + cross-register uniqueness via `IdentityCheckService` + within-file duplicate detection), present address, admission date (multiple formats + Excel serials) all enforced; NID optional but format/uniqueness-checked; a legacy MemberID in the file is kept as `member_no` when free and flagged when taken. Imported members start with share_amount 0.
- **Deviation — spreadsheet library:** the plan assumed a spreadsheet package could be installed. This VM's network repeatedly stalled GitHub dist downloads (composer install hung on 0-byte downloads for 15+ minutes; direct codeload curl also timed out), so `maatwebsite/excel` could not be installed. Step 9A instead ships a small dependency-free `App\Services\XlsxService` (ZipArchive + SimpleXML): reads single-sheet xlsx (shared strings, inline strings, numerics/serial dates) and CSV, writes valid single-sheet xlsx that Excel/LibreOffice open. composer.json/lock were reverted to the committed state. Swapping in PhpSpreadsheet later is a drop-in change behind the same two call sites (controller read + report write) if the network ever allows it.
- Environment incidents: the VM was replaced twice during this step. Recovery notes for next time: the platform replay's own `apt-get update` can hang indefinitely on a dead connection while holding the apt lists lock — killing just that apt-get lets the replay finish (best-effort by design) and frees the lock; `~/.cache/composer` survives replacements even though system packages do not; the Composer installer script fails here with "Failed to decode zlib stream" while a direct `composer.phar` curl works.

## Step 10 — notes

Status: **done**. Tests: 99 passed, 1 skipped (Chromium PDF) — full suite green. 63 tables, 604 lang keys in both en/bn.

- Tables: `deposit_products` (the binding product master: canonical codes SAVINGS/CSAV/DPS/FDR, rates/minimums/maturity/premature penalty/auto-renew/GL prefix, `withdrawal_rules` JSON, plus a generic `config` JSON for product-specific structured data), `deposit_accounts` (account_number via NumberingService, profit-rate snapshot at opening, maturity computed at opening, cached balances whose source of truth stays `account_transactions`), `deposit_liens` (loan_proposal_id/loan_account_id plain columns per the round-3 fix; FKs arrive in Steps 16/17), `other_accounts` (the generic GL-style Other Account Opening register).
- The Step 3A forward FK `voucher_lines.deposit_account_id → deposit_accounts.id` is now enforced. One adaptation: the FK carries NO `ON DELETE SET NULL` — MySQL error 3823 forbids referential actions on a column used in a CHECK constraint (3A's `chk_voucher_lines_one_account`), and RESTRICT is the safer accounting semantic anyway.
- Pages (gated `deposits.view`/`deposits.open`, products CRUD gated `deposits.profit-setup`): Deposit Account Opening (member lookup by member no → samity readonly, MEMBER PROFILE panel, product select with rate snapshot default, term picker driven by product config, account-level nominee override creating a fresh nominee row), Account List (all plan filters, the 15-column table + mobile cards), Other Account Opening (form + register), Deposit Products (full CRUD — every product incl. the LTS sample is editable/deletable data; delete blocked once accounts exist).
- `DepositAccountService`: opening (numbering, snapshot, maturity = opening + product months or per-opening term), `activeLienTotal`, `assertClosable` / `assertWithdrawable` (an Active lien blocks closing and any withdrawal that would breach the lien amount), `prematureClosePreview` (balance / penalty / payable — closing before maturity deducts the product's premature penalty rate). The closing *settlement* (deposit_closings + payout posting) lands in Step 12 as planned; Step 11 wires the money movement.
- Opening form's Amount maps to `installment_amount` (DPS/LTS installment; harmless elsewhere) — per the acceptance, a freshly opened account lists with balance 0; opening money itself flows through Step 11 receives.
- **Long Term Savings seed (user spec, 2026-10-21):** product code `LTS`, দীর্ঘ মেয়াদী সঞ্চয়, seeded as a SAMPLE — all figures live in the editable `config` JSON (fixed amounts 50–1000, daily/weekly/monthly, terms 1–5 years, 260 collection days/year, maturity rates 7/9/11/13/15%, premature slabs 0/7/9/11/13%, and the business rules: payout within 1 month of maturity, deposits start the day after approval, holiday collections roll forward, late deposits made up within the month, membership cancellation closes early under the slabs). The printed maturity payout table was transcribed from the user's leaflet photo and is stored verbatim as data (no formula): 50 → 456/1648/3889/7521/12963; 100 → 913/3298/7774/15045/25930; 200 → 1827/6598/15551/30094/51866; 300 → 2740/9896/23326/45141/77799; 400 → 3654/13196/31103/60190/103704; 500 → 4567/16495/38879/75238/129668; 600 → 5481/19794/46655/90286/155602; 700 → 6394/23093/54431/105334/181536; 800 → 7308/26392/62207/120382/207470; 900 → 8221/29691/69983/135430/233404; 1000 → 9135/32991/77760/150479/259339 (profit ৳ for terms 1–5 yr). Both cross-check rows given with the spec match. A feature test pins four rows + slabs and proves the admin can edit the table and delete an unused product.
- Forward-fix (anticipated pattern): Step 3A's `PostingServiceTest` posted voucher lines against bare fixture deposit ids (9001…); with the FK now live those resolve to real deposit accounts created on the seeded member/product inside each test.

## Step 11 — notes

Status: **done**. Tests: 106 passed, 1 skipped (Chromium PDF) — full suite green. 65 tables, 632 lang keys in both en/bn.

- Tables: `deposit_transactions` (product detail: tran_type Receive/Payment, product label Savings/DPS/FDR, voucher link, entry/cancel audit) and `deposit_transfers` (plan-exact columns; its voucher is found via the sub-ledger ref on cancel).
- `DepositTransactionService` is the only deposit money path and posts everything through PostingService: receive (Receipt voucher, `<prefix>.receive` mapping, deposit RECEIVE + cash CASH_IN movements), payment (Payment voucher, `.pay`, negative movements; note Payment-type vouchers sit Pending until authorized per 3A rules while balances move at posting, as the engine specifies), transfer (Contra voucher, `deposit.transfer`, TRANSFER_OUT/IN pair, no cash leg), share receive/payment (`share.receive`/`share.pay`, `account_type='share'` with account_id = members.id — no deposit account is ever created for shares).
- Guards, all inside the posting transaction with FOR UPDATE on the account/member rows only: account must be active/unclosed; debits respect the product `minimum_balance` and the Step 10 lien guard; share payments cannot exceed `members.share_amount`. Cached balances (`total_credit`/`total_debit`/`current_balance`, and `members.share_amount` for shares) are resynced from the non-cancelled `account_transactions` chain after every post/cancel — the sub-ledger stays the single source of truth.
- Cancellation is latest-movement-only per account (cancelling an older movement would corrupt the running-balance chain; Step 27 adds full reversal entries). Cancelling flags the detail row (cancel_by/at), cancels the voucher via PostingService, and resyncs caches — transfer cancel requires the movement to be latest on BOTH accounts.
- Pages (shared two-column form + ACCOUNT SNAPSHOT pattern): Deposit Receive (with product presets Savings/DPS/FDR via query, e.g. `?product=DPS`), Deposit Payment, Deposit Transfer (dual member lookup, balances readonly, transfers table with cancel by/on), Share Receive/Payment (mode toggle). History on receive/payment pages lists the account's latest 20 detail rows with cancel actions. Gated `deposits.receive` / `deposits.pay` / `deposits.transfer`.
- Acceptance verified by tests: receive ৳1000 → ৳1000, pay ৳200 → ৳800 with balanced vouchers + balanced trial balance + GL control at 800; transfer moves ৳400 with the two-account sum unchanged; FDR receive lands on the 2030 control; share flows move `members.share_amount` exactly.
