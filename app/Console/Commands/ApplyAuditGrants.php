<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Applies the append-only grant for audit_logs (plan Step 1/B1): the app
 * DB user keeps INSERT+SELECT on audit_logs but loses UPDATE/DELETE.
 *
 * Run after every `migrate:fresh` (dropping the table drops its grants)
 * and once in production provisioning. Needs MySQL root credentials in
 * DB_ROOT_USERNAME / DB_ROOT_PASSWORD.
 */
class ApplyAuditGrants extends Command
{
    protected $signature = 'db:apply-grants';

    protected $description = 'Revoke UPDATE/DELETE on audit_logs from the app DB user (append-only)';

    public function handle(): int
    {
        $rootUser = env('DB_ROOT_USERNAME', 'root');
        $rootPass = env('DB_ROOT_PASSWORD');
        if (! $rootPass) {
            $this->warn('DB_ROOT_PASSWORD not set — skipping (set it in production provisioning).');

            return self::SUCCESS;
        }

        $appUser = config('database.connections.mysql.username');
        $dsn = 'mysql:host='.config('database.connections.mysql.host').';port='.config('database.connections.mysql.port');
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        // Table-level exceptions to a database-wide grant need MySQL 8's
        // partial revokes; persist the setting so the restriction is real.
        // The variable applies to NEW sessions only — enable, reconnect.
        $boot = new PDO($dsn, $rootUser, $rootPass, $opts);
        $boot->exec('SET PERSIST partial_revokes = ON');
        $boot = null;

        $pdo = new PDO($dsn, $rootUser, $rootPass, $opts);

        $databases = array_unique([
            config('database.connections.mysql.database'),
            'tpda_esheba', 'tpda_esheba_test',
        ]);

        foreach ($databases as $db) {
            $exists = $pdo->query("SHOW DATABASES LIKE '{$db}'")->fetchColumn();
            if (! $exists) {
                continue;
            }
            foreach (['localhost', '127.0.0.1', '%'] as $host) {
                $account = "'{$appUser}'@'{$host}'";
                $has = $pdo->query("SELECT COUNT(*) FROM mysql.user WHERE User = '{$appUser}' AND Host = '{$host}'")->fetchColumn();
                if (! $has) {
                    continue;
                }
                try {
                    $pdo->exec("REVOKE UPDATE, DELETE ON `{$db}`.`audit_logs` FROM {$account}");
                    $this->info("Revoked UPDATE/DELETE on {$db}.audit_logs from {$account}");
                } catch (\PDOException $e) {
                    // Error 1147: MySQL cannot carve a table-level exception
                    // out of a database-wide grant. Immutability is enforced
                    // by the audit_logs triggers (migration) — this command
                    // is a best-effort extra layer, so report and continue.
                    $this->warn("Grant-level revoke not applicable on {$db} for {$account} (DB triggers enforce append-only).");
                }
            }
        }

        return self::SUCCESS;
    }
}
