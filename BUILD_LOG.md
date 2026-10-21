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

## Step 12 — notes

Status: **done**. Tests: 111 passed, 1 skipped — full suite green. 67 tables, 654 lang keys.

- Tables: `deposit_blocks` (block/clear dates, amounts, reasons, deposit/withdrawal flags, audit) and `deposit_closings` (balance, profit_payment, closing_charge, payable, close/cancel audit).
- Guards wired into `DepositTransactionService`: uncleared withdrawal blocks reserve their amount in `assertDebitAllowed` (floor = lien total + block total, error `amount_blocked`); uncleared deposit blocks stop receives (`deposits_blocked`). Clearing a block restores full movement.
- `DepositClosingService`: payable = balance + profit_payment − closing_charge (setting `deposit.closing_charge`) − premature penalty (Step 10 preview). One Payment voucher: Dr product control (balance) + Dr profit expense (profit), Cr cash (payable), Cr fee income 4035 (charge, via `deposit.close.charge`), Cr penalty income 4020 (via the new `deposit.close.penalty` mapping added to GlMappingSeeder). Closing sets `closing_date` + `is_active=false`; cancel reopens the account and restores the balance from the sub-ledger. A zero-balance closing posts no voucher.
- Pages: Transaction Block / Deposit Block Clear (one page, two modes) and Deposit Closing with a live preview (balance / profit / charge / penalty / payable). Gated `deposits.block` / `deposits.close`.

## Step 13 — notes

Status: **done**. Tests: 116 passed, 1 skipped — full suite green. 73 tables, 688 lang keys.

- Tables: `profit_setups` (deposit_account_id per plan, serial per account), `daily_balances` (ENUM deposit/loan, unique per account/day — written by the Step 27 EOD job; the profit engine reads ONLY this table for D/M/X/A math), `profit_distributions` (+lines), `profit_payments`, `transaction_profiles`.
- `ProfitEngine` implements the binding formulas (D Σ daily, B as-on, M min, X max, A average, O opening, N zero; rate is a percent, days = inclusive period count, brick/math HALF_UP). B/O fall back to the cached balance when no snapshot exists. Manual distributions use the operator's rate for all accounts; automatic runs resolve per-account setup rate → product default.
- Posting: one Journal voucher per run — Dr profit expense, Cr product control (net), Cr TDS payable 2040 when `profit.tax_withholding_rate` > 0. Profit payment pays against posted lines' `profit_pending` FIFO via `deposit.profit.pay`. Acceptance hand-check pinned in tests: 10,000 avg × 15% × 31d = 127.40; setup override 10% = 84.93; TDS 10% → net credit 114.66; exclude-zero skips snapshot-less accounts.
- Pages: Profit Setup, Manual + Automatic Distribution (shared page), Distribution List, Distribution Details (print + Post to Account), Profit Payment (paid/due readonly), Transaction Profile / Update Installment. Gated `deposits.profit-setup` / `deposits.profit-post` (catalog names).

## Step 14 — notes

Status: **done**. Tests: 121 passed, 1 skipped — full suite green. 73 tables (no new tables), 720 lang keys.

- `ReportService` registry per plan ({key, title, filters, query, pdf_view}) with all twelve deposit reports: FDR/DPS/Savings registers (7-option account-status dropdown incl. maturity-between and upto-open), Collection (= receive) and Refund (= payment) registers, Profit Payment register, DPS Target vs Collection (monthly-equivalent target = installment × months in period), Monthly Deposit Transaction (year spinbutton, 12 rows + totals), Member Profit Balance (distributed/paid/due with product-type filter), Zero Transaction report. Every query honours samity/employee(field officer)/member filters — tests prove rows change when the samity filter changes.
- One generic page (`Reports/DepositReports`) with per-report filter panels + totals row; Print renders `reports/generic.blade.php` (org header EN/BN from OrganizationProfile) through PdfService — Chromium when available, mPDF (Bengali fonts) for >200 rows or when Chromium is missing (`renderHtmlWithFallback`, new). Gated `reports.view` / `reports.print`.

## Step 15 — notes

Status: **done**. Tests: 126 passed, 1 skipped — full suite green. 76 tables, 747 lang keys.

- Tables: `loan_products` (plan-exact: code 3001/3002/3003 seeded, profit_rate as THE rate, rate_method ENUM, terms/amounts, allowed_frequencies + penalty_rule + fee_config JSON, eligibility rules min_cycle/max_cycle/min_savings_balance/max_concurrent_loans/min_member_months, gl_mapping_prefix), `installment_frequencies` (10 codes D/W/M/Q/H/Y/AtMaturity/F-15days/S-single/N-na with EN/BN labels), `emi_option_templates` (FK to loan_products).
- `LoanRateGuard` implements the binding MRA rule: the 24% cap (`loan.max_service_charge_rate` setting) applies to the declining-equivalent effective rate — declining = nominal; flat = APR of the implied 1-year monthly schedule via IRR bisection (15% flat = 26.64% → creation rejected; 12% flat ≈ 21.5% → allowed). TPDA products seed 15% declining.
- Frequencies: `InstallmentFrequency::enabled()` filters the master by the `loan.enabled_frequencies` setting — disabling W removes Weekly from the installment-settings form (acceptance-tested). EMI template rates must equal the product rate unless the user holds the new `loans.rate-override` permission (added to the catalog with `loans.products`; catalog now 141 permissions — Step 2's pinned-count test forward-fixed).
- Pages: Loans/Products (full CRUD with effective-rate column) and Loans/InstallmentSettings (Manage Installment Settings per plan). Gated `loans.products`.

## Step 16 — notes

Status: **done**. Tests: 131 passed, 1 skipped — full suite green. 77 tables, 780 lang keys.

- Table `loan_proposals` (product FK replaces free-text account type; profit snapshots from the engine) + the Step 10 forward FK `deposit_liens.loan_proposal_id` enforced. One extra column beyond the plan list: `guarantors` JSON — the proposal form's Add Guarantor rows are carried into the Step 17 opening form instead of being dropped.
- `ScheduleEngine::preview()` implements the binding Step 17 formulas NOW so proposal and opening never diverge: flat = amount × rate × term-years (D n/365, W n/52, M n/12, Q n/4, H n/2, Y n, 15-days n/24); declining = PMT amortization (period rate = rate/periods-per-year), brick/math scale 2 HALF_UP, last flat installment absorbs rounding. Golden checks: declining 15% on 12,000 M×12 → installment 1083.10, profit 997.20; flat 12% on 10,000 → profit exactly 1200.00, last installment 933.37.
- `LoanEligibilityService`: cycle window, concurrent-loan cap (per member and per NID across all branches — the over-indebtedness reading, since the plan names no separate numeric cap), savings minimum, membership-age minimum. "Active loans" at this stage = Approved proposals. MRA cap + rate precedence enforced on submit via LoanRateGuard.
- Approval creates the FDR `deposit_liens` row (Active, loan_proposal_id set, loan_account_id null until Step 17); the Step 11 payment guard already blocks withdrawals breaching it (acceptance-tested end to end).
- Pages: Loan Proposal (member lookup, MEMBER PROFILE panel, live ScheduleEngine preview via JSON endpoint, guarantor rows) and Proposal List (16-column, samity/officer/member/status/date filters, approve/reject, Open Loan link for approved rows → Step 17 form). Gated `loans.propose` / `loans.approve` / `loans.view`.

## Step 17 — notes

Status: **done**. Tests: 137 passed, 1 skipped — full suite green. 82 tables, 804 lang keys.

- Tables: `loan_accounts` (full plan column set incl. plain `invoice_id` — FK arrives Step 35 — and the binding status lifecycle; opening creates **Opened**), `guarantors` (national_id encrypted + SHA-256 hash), `loan_fees` (one row per fee type, ledger_id resolved via the new `loan.fee.<type>` mappings → income ledgers 4030–4035, voucher_id filled at posting), `loan_schedules`, `due_penalty_setups`. Forward FK `voucher_lines.loan_account_id` enforced.
- `ScheduleEngine::rows()` is the generator: declining amortization (profit = outstanding × period rate; final row absorbs rounding), flat equal splits (final row absorbs), due dates stepped then shifted off Fri/Sat weekends + the `holidays` table; weekly loans first align to the samity's meeting day. Golden tests: declining 12,000 @15% M×12 — row 1 profit 150.00 / principal 933.10, Σprincipal exactly 12,000.00; flat weekly 5,200 @10% ×52 — profit 520.00, splits 100+10; Friday first-due shifts to Sunday, and to Monday when Sunday is a holiday.
- `LoanOpeningService`: rate snapshot + precedence + MRA cap at opening (override 25% blocked; 15% flat override blocked at ≈26.6% effective), fee Receipt voucher through PostingService, proposal lien's `loan_account_id` filled on open, double-opening a proposal refused. Down payment and product-loan total bill are recorded data (bill = loan_amount + down_payment); disbursement money moves in Step 18. Eligibility is not re-run at opening (proposal stage owns it).
- Pages: one Opening page with member/staff/product modes (MEMBER/EMPLOYEE PROFILE panels, live preview, guarantor rows, fee inputs, product-mode LOAN LIST) and Due Penalty Setup (search + per-loan penal charge upsert). Gated `loans.open` / `loans.penalty`.

## Step 18 — notes

Status: **done**. Tests: 143 passed, 1 skipped — full suite green. 84 tables, 835 lang keys.

- Tables: `loan_receives` (plan columns + voucher link) and `loan_receive_allocations` (extra, documented: per-row split of each receipt — required for exact latest-only reversal and the history splits). One more extra column: `loan_receives.deposit_voucher_id` so a transfer-funded receipt can reverse its deposit side too.
- `LoanMoneyService`: disbursement posts `loan.disbursement` (Dr 1100 / Cr cash) and flips Opened → Disbursed (+disbursed_by/at). Receives allocate in the configured order with a two-phase reading of the binding rule: penalty is receipt-level (setup charge per overdue unpaid row, FIFO; officer-entered penalty caps it), then installments walk FIFO with profit before principal inside each row. First receipt flips Disbursed → Active; full payment → Closed.
- Advance/over-payment: paying ahead applies to future rows (the advance, auto-applied at due time); beyond total outstanding, the excess rides the receipt voucher as a credit to 2060 Accounts Payable (`loan.advance`) and is refunded by a Payment voucher (`loan.advance.refund`); 2060 nets to zero (test-pinned). With `loan.allow_overpayment` off, future rows are capped out and excess cannot be taken.
- Transfer-from-deposit posts two balanced vouchers (Step 11 deposit payment + loan receive; actor cash nets zero) and cancel reverses both sides. Cancel enforces the binding latest-receipt-only rule and restores schedule rows from the allocation rows.
- Manual receive takes explicit investment/profit/penalty splits (sum must equal the amount; each bucket capped at outstanding). Pages: Disbursement List, Installment Receive (auto/manual/staff modes + ACCOUNT SNAPSHOT), Receive Cancel (member/staff), Installment History (wide audit table with running outstanding). Gated `loans.disburse` / `loans.receive` / `loans.view`.
- Allocation semantics note: an earlier draft allocated profit globally before principal (a single installment payment would have prepaid all future profit); corrected to per-installment completion — pinned by tests (row 1: profit 150.00 + principal 933.10).

## Step 19 — notes

Status: **done**. Tests: 148 passed, 1 skipped — full suite green (149 test methods; +6 new). 90 tables, 882 lang keys.

- Tables: `loan_reschedules`, `loan_waivers` (+ extra `row_splits` JSON — per-row due reductions so a waiver cancel restores dues exactly), `loan_penalties` (+ extra `schedule_id` — remembers which row the additional profit landed on so cancel removes it exactly), `writeoffs`, `writeoff_collections`, `insurance_claims`. `loan_schedules.superseded_by` FK → loan_reschedules.
- Reschedule (`LoanAdjustmentService::reschedule`): unpaid rows are settled by construction (dues trimmed to paid amounts, status Paid) and kept as superseded history; outstanding principal + (outstanding profit + additional profit) is re-spread with `ScheduleEngine::respreadRows()` (equal splits, last absorbs, working-day-shifted dates, weekly aligned to samity day); installment numbers continue from the old maximum; loan frequency/count/dates/totals recomputed from all rows; reschedule fees reuse the Step 17 pattern (loan_fees rows + one Receipt voucher).
- Waiver: reduces principal/profit dues FIFO from the first unpaid row (never below paid amounts); charge_waive works on the penalty pool (below); non-cash — no GL voucher (plan specifies none). A waiver that zeroes the loan closes it; cancel restores dues from `row_splits` and reopens.
- Penalty pool (binding integration with Step 18): `LoanMoneyService::penaltyDue()` now = per-row setup charges (overdue, net of paid) + active `loan_penalties.penalty_amount` − active waivers' `charge_waive` − penalty already collected beyond setup caps. Receipt allocation collects per-row caps first, then anchors any remaining pool amount on an unpaid row so the paid_penalty trail stays exact; manual-mode penalty bucket is capped by the same total.
- Write-off: snapshots outstanding splits into `writeoffs`; posts a Journal voucher via the `loan.writeoff` mapping for the principal only (Dr 1120 provision / Cr 1100) + a WRITEOFF sub-ledger movement; status → WrittenOff. Recovery (`collectWriteoff`): Receipt voucher — principal via `loan.receive`, profit via `loan.receive.profit`, charge via `loan.penalty` — + RECOVERY movement; the loan stays WrittenOff and recoveries accumulate against it.
- Insurance claims: file (documents JSON) → approve/reject → pay. Paying settles the attached loan's outstanding up to the claim amount through a normal `LoanMoneyService::receive()` (comment carries the claim id), then marks the claim Paid. Any remainder is the nominee's cash entitlement handled by the officer; savings release is a normal Step 11 deposit payment — no insurance GL mapping was invented.
- Pages: Loan Reschedule, Loan Waiver, Additional EMI Penalty, Write Off (entry + recovery), Insurance Claims (file/decide/pay). Gated `loans.reschedule` / `loans.waive` / `loans.penalty` / `loans.writeoff` (+ propose/approve/receive for claim actions). All money still moves only through PostingService.

## Step 20 — notes

Status: **done**. Tests: 152 passed, 1 skipped — full suite green. 91 tables, 932 lang keys.

- Table: `loan_loss_provisions` (plan columns + voucher_id/created_by audit). Eleven loan registers on the Step 14 engine pattern (`LoanReportService` registry → generic web table + `reports.generic` PDF): Recovery Details (cash / deposit-transfer split), Collection Register (+ Month Wise Collection mode), Member Loan Balance (loan-type modes: installment receivable / overdue / receive only / expire / closed / active / all; profit-rate minimum filter), Samity-wise and Field-Officer-wise Outstanding, Rent Report, Disbursement Register (incl. disbursement day-of-month + EMI opening type All/New/Reschedule — reschedule = has a Step 19 reschedule row), Disbursement & Collection Register (+ account status), Fully Paid Register, Waiver Report, Written-Off Register (with recoveries).
- LLP (`LlpService`): classification by days overdue of the earliest unpaid installment — standard 0, watchlist 1–30, substandard 31–180, doubtful 181–365, loss 365+ — rates from `settings.llp.rates` {1/5/25/75/100}. Basis is binding `net_outstanding`: outstanding principal − the member's CSAV (compulsory savings) balances, floored at zero. Hand-check pinned in tests: 12,000 loan with 2,000 CSAV → basis 10,000 → standard 1% = 100.00; a 50-day-overdue 3,000 loan → substandard 25% = 750.00.
- Posting a run journals the DELTA per branch so the 1120 balance rolls forward to the required total: increase via the `loan.provision` mapping (Dr 5030 / Cr 1120), release as an explicit reversal (Dr 1120 / Cr 5030); a repeat run with unchanged data posts nothing (test-pinned). `actual_llp` stores the post-run balance and coverage = actual/required. Note: 1120 is a contra-asset in the Asset group, so `PostingService::ledgerBalance()` reports it debit-normal (negative) — LlpService reads the provision balance negated, in its natural credit direction.
- Interpretations (documented, plan-silent spots): the Member Loan Balance profit-rate spinbutton is a minimum-rate filter; the Rent Report's "Rent Applied" type groups installments falling due in the period by product (due vs collected); Fully Paid Register dates by the loan's closing update timestamp. LLP page gated `reports.view` (the plan names no dedicated LLP permission in the catalog).

## Step 20A — notes

Status: **done**. Tests: 155 passed, 1 skipped — full suite green. 92 tables, 942 lang keys.

- Tables: `balance_import_batches` (9A-shaped: samity, transaction_date, add_members flag, source, status Preview→Queued→Processing→Completed, staged rows JSON, failures JSON) + `loan_receives.is_migrated` (the Step 18 table gains the flag here, per the plan). New GL mapping `migration.opening` (Dr 1100 Member Loans Portfolio / Cr 3020 Retained Earnings — Fund Balance) added to GlMappingSeeder.
- Flow mirrors Step 9A: Excel (XlsxService) or blank-grid dry run → staged preview with grouped columns (Member | Loan Information | Deposit Information) + totals footer → confirm → queued `ImportBalancesJob` (100-row chunks, per-row transactions, failures recorded, error-report xlsx download). Pages gated `settings.view` / `settings.general` like member migrations.
- Hydration (binding, implemented exactly): loan_accounts carry the ORIGINAL loan_amount; profit_amount/total_repayment come from a FULL-TERM `ScheduleEngine` schedule (product rate_method + imported rate snapshot); the imported total_receive is applied FIFO — profit before principal inside each installment (Step 18 semantics) — and recorded as ONE `loan_receives` row with `is_migrated = true` and `voucher_id = null` (no GL voucher; the cash predates go-live). Plan acceptance pinned in tests: 10,000 + 1,500 profit (flat 15%), 4,000 received → rows 1–4 Paid, row 5 Partial (125.00 profit + 41.68 principal), remaining due exactly 7,500.00, splits 3,375.00 principal / 625.00 profit.
- Opening voucher: one Journal per member on the current business day — outstanding principal via `migration.opening`, each deposit balance as Dr 3020 / Cr 2010 savings control, with OPENING sub-ledger movements for the loan and deposit accounts; deposit caches resynced from the sub-ledger afterwards. Trial balance stays balanced (test-pinned). The form's transaction date is the value date recorded on the receive/accounts; vouchers post on the migration business day (PostingService's closed-date rule).
- Deposits land in SAVINGS (General) / CSAV (Compulsory) accounts — opened on demand, reused when an active account already exists. One bad row (total_receive > total_loan) fails validation while the rest of the batch still imports (test-pinned).
- Deviation (documented): Add Members = Yes creates a stub member from the file's legacy ID + name only (the grid carries nothing else). `members.mobile` is NOT NULL + unique, so the stub gets a placeholder mobile derived from the legacy ID digits (`01` + zero-padded), checked for collision (collision = row failure); officers must correct it on the member profile, and no member portal user is created for stubs.

## Fix — language switcher (2026-10-21, owner report)

- Bug: UI language lagged one switch behind and appeared non-persistent. Root cause: both layouts synced the i18n store in `useEffect` (after render); the module store update never re-rendered components, so labels always showed the previous locale.
- Fix: layouts now sync the store during render (children render after, always current). Switch also persists via a 1-year `locale` cookie (SetLocale priority: user → session → cookie → settings default).
- Default UI language is now English everywhere (settings seed, users.locale DB default via migration, factory, .env.example, JS fallbacks) — still fully changeable per user and in Settings.
- Tests: new `LocaleSwitchTest` (3 tests: default en, switch persists to DB + applies on next render, guest cookie honoured). Full suite: 158 passed, 1 skipped.
