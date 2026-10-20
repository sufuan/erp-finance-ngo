<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Nightly database backup (plan Step 1/C5): mysqldump → object storage
 * (BACKUP_DISK, default s3). Scheduled nightly in routes/console.php;
 * restore is rehearsed in Step 44.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--disk= : Override the backup disk}';

    protected $description = 'Dump the MySQL database and upload it to the backup disk';

    public function handle(): int
    {
        $disk = $this->option('disk') ?: env('BACKUP_DISK', 's3');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');
        $db = config('database.connections.mysql.database');
        $user = config('database.connections.mysql.username');
        $pass = config('database.connections.mysql.password');

        // Credentials go in a temp defaults file, never on the command line.
        $defaults = tempnam(sys_get_temp_dir(), 'tpda-mysql-');
        file_put_contents($defaults, "[client]\nuser={$user}\npassword={$pass}\nhost={$host}\nport={$port}\n");
        chmod($defaults, 0600);

        $file = 'backups/'.$db.'-'.now()->format('Ymd-His').'.sql';
        $local = storage_path('app/'.$file);
        if (! is_dir(dirname($local))) {
            mkdir(dirname($local), 0775, true);
        }

        try {
            $process = new Process([
                'mysqldump', '--defaults-extra-file='.$defaults,
                '--single-transaction', '--routines', '--triggers',
                '--result-file='.$local, $db,
            ]);
            $process->setTimeout(600);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error('mysqldump failed: '.$process->getErrorOutput());

                return self::FAILURE;
            }
        } finally {
            @unlink($defaults);
        }

        Storage::disk($disk)->put($file, file_get_contents($local));
        $this->info("Backup written to {$disk}:{$file}");

        return self::SUCCESS;
    }
}
