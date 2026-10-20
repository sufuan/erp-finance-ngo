# Deployment notes (Step 1 infrastructure)

- **Queue workers:** `supervisor.conf` (this folder) runs `queue:work redis`.
  On the dev VM Supervisor is not installed; the same command is run directly when needed.
- **Scheduler cron:** `* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1`
- **Nightly DB backup:** `db:backup` artisan command (02:00 via scheduler) — mysqldump to the
  object-storage disk (`BACKUP_DISK`, default `s3`; set AWS_* in `.env`). Restore rehearsal: Step 44.
- **Object storage:** uploads + backups live on the S3-compatible disk (`FILESYSTEM_DISK` /
  `BACKUP_DISK` in `.env.example`). The `private` disk (storage/app/private) is the local
  fallback and is never web-accessible — files are served by `FileController` only.
- **TLS:** terminate at the reverse proxy (nginx/Caddy) in production; `APP_URL` must be https.
- **audit_logs append-only:** after any `migrate:fresh`, re-run
  `php artisan db:apply-grants` (needs `DB_ROOT_USERNAME`/`DB_ROOT_PASSWORD`) to re-apply the
  UPDATE/DELETE revoke on `audit_logs` for the app DB user.
