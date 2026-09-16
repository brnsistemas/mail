<?php

namespace App\Console\Commands;

use App\Services\BackupArchive;
use Illuminate\Console\Command;

class BackupMail extends Command
{
    protected $signature = 'brnmail:backup {--key-file=}';

    protected $description = 'Cria arquivo autenticado e criptografado; exige chave externa de 32 bytes. Não é backup offsite.';

    public function handle(BackupArchive $service): int
    {
        $path = $this->option('key-file');
        if (! $path || ! is_file($path) || (fileperms($path) & 0077) !== 0) {
            $this->error('Forneça arquivo privado de chave, modo 600.');

            return 1;
        }
        try {
            $key = file_get_contents($path);
            $archive = $service->export($key);
            $service->inspect($archive, $key);
            $dir = storage_path('app/private/backups');
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $file = $dir.'/brnmail-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.enc';
            file_put_contents($file, $archive, LOCK_EX);
            chmod($file, 0600);
            $this->line('backup='.$file);
            $this->line('sha256='.hash('sha256', $archive));
            $this->line('Arquivo validado. Restore e cópia externa são gates separados.');

            return 0;
        } catch (\Throwable) {
            $this->error('Backup não concluído. Nenhuma cópia foi declarada válida.');

            return 1;
        }
    }
}
