<?php

// Exemplo operacional: use somente em uma VPS de restauração isolada.
declare(strict_types=1);
use App\Services\BackupArchive;
use Illuminate\Contracts\Console\Kernel;

require '/srv/brnmail/vendor/autoload.php';
$app = require '/srv/brnmail/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    if (! $app->environment('local')
        || config('database.connections.mysql.database') !== 'brnmail_restore'
        || config('brnmail.external_enabled')
        || config('brnmail.local_demo')
        || count($argv) !== 3
        || ! is_file($argv[1]) || ! is_file($argv[2])) {
        throw new RuntimeException('invalid_restore_target');
    }
    $counts = $app->make(BackupArchive::class)->restore(
        file_get_contents($argv[1]), file_get_contents($argv[2])
    );
    echo json_encode(['restored_rows' => $counts], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Restauração recusada ou não concluída. Confira destino vazio, versão, arquivos e chaves em canal privado.\n");
    exit(1);
}
