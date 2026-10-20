# TPDA e-Sheba

Microfinance / samity / NGO management system for **Talgachi Poor Development Association (T.P.D.A.)** — তালগাছী দরিদ্র উন্নয়ন সংস্থা (Reg. সিরাজ/৫৮৫/সমাজসেবা/২০০০).

Web admin + member portal (Laravel 13 + Inertia + React) with a hybrid REST API (`/api/v1`) for the **TPDA e-Sheba** Flutter field-worker app. Full Bangla + English i18n, branch-scoped RBAC, double-entry accounting core.

## Stack

| Layer | Choice |
|---|---|
| PHP | 8.4 |
| Framework | Laravel 13 + Inertia.js + React 19 + Tailwind CSS 4 |
| Database | MySQL 8.4 LTS (utf8mb4) — **SQLite is never used** |
| Cache / queues | Redis |
| PDF | Headless Chromium (spatie/laravel-pdf / Browsershot); mPDF+OTL fallback for >200-row registers |
| Money | `DECIMAL(18,2)` + `brick/math` (never floats) |

## Requirements

- PHP 8.4 with: mysql, mbstring, xml, curl, zip, gd, intl, bcmath, redis
- Composer 2
- MySQL 8.4 (databases `tpda_esheba` and `tpda_esheba_test`)
- Redis
- Node 20+ / npm
- Chromium for PDFs: `npx puppeteer browsers install chrome`

## Install

```bash
composer install
npm install
npx puppeteer browsers install chrome   # for Browsershot PDF rendering
cp .env.example .env                     # set DB_USERNAME / DB_PASSWORD
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve                        # http://localhost:8000
```

Or with Docker: `docker compose up` (app + mysql:8.4 + redis).

## Default login

Seeded in Step 2 — see **BUILD_LOG.md** ("Default logins") for the Super Admin
username/password. TOTP setup is required on first login for admin roles.

## Tests

```bash
php artisan test        # runs against the MySQL test database (tpda_esheba_test)
```

The suite includes the translation key-parity check (en.json vs bn.json),
money/accounting invariant tests, and the Bengali PDF smoke test.

## Build notes

- `BUILD_LOG.md` — per-step status, test results, environment versions, deviations.
- `docs/schema.md` — regenerated at the end of every build step (`php artisan schema:markdown`).
- The build follows the step-by-step plan one step at a time; each step is a separate git commit.
