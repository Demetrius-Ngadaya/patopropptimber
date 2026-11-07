<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * mysqldump of the database to storage/app/backups (gzip), keeping N days.
 * Copy the folder off the server (rclone, DirectAdmin backups ...) for safety.
 */
class BackupDatabase extends Command
{
    protected $signature = 'mauzo:backup';

    protected $description = 'Back up the MySQL / MariaDB database to storage/app/backups';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->warn('Backups are only supported for MySQL / MariaDB.');

            return self::SUCCESS;
        }

        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir, 0750);
        $file = $dir.'/'.$config['database'].'_'.now()->format('Y-m-d_His').'.sql.gz';

        // Credentials go through a temporary option file, never the command line (visible in `ps`).
        $cnf = tempnam(sys_get_temp_dir(), 'mzp');
        file_put_contents($cnf, "[client]\nuser=\"{$config['username']}\"\npassword=\"{$config['password']}\"\nhost=\"{$config['host']}\"\nport=\"{$config['port']}\"\n");
        chmod($cnf, 0600);

        try {
            $process = Process::fromShellCommandline(sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers %s | gzip > %s',
                escapeshellcmd(config('mauzo.backup.mysqldump')),
                escapeshellarg($cnf),
                escapeshellarg($config['database']),
                escapeshellarg($file),
            ));
            $process->setTimeout(1800)->run();

            if (! $process->isSuccessful() || ! file_exists($file) || filesize($file) < 100) {
                @unlink($file);
                $this->error('Backup failed: '.$process->getErrorOutput());

                return self::FAILURE;
            }
        } finally {
            @unlink($cnf);
        }

        $keep = config('mauzo.backup.keep_days');
        foreach (File::glob($dir.'/*.sql.gz') as $old) {
            if (filemtime($old) < now()->subDays($keep)->getTimestamp()) {
                File::delete($old);
            }
        }

        $this->info('Backup written to '.$file.' ('.round(filesize($file) / 1024).' KB)');

        return self::SUCCESS;
    }
}
