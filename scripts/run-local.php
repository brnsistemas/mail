<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

// All child processes are this project's localhost runtime. No global service control.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('brnmail.external_enabled') || config('brnmail.transport') !== 'local' || config('app.url') !== 'http://127.0.0.1:8876' || config('database.connections.mysql.database') !== 'brnmail' || config('database.connections.mysql.url') || config('database.connections.mysql.host') !== '127.0.0.1' || (string) config('database.connections.mysql.port') !== '33461' || config('database.default') !== 'mysql' || config('database.redis.default.host') !== '127.0.0.1' || (string) config('database.redis.default.port') !== '16381') {
    fwrite(STDERR, "Recusado: este launcher é apenas local, sem envio externo.\n");
    exit(1);
}
if (! extension_loaded('pcntl')) {
    fwrite(STDERR, "pcntl é necessário para encerrar os processos filhos com segurança.\n");
    exit(1);
}
$lock = fopen($root.'/storage/app/private/local-runtime.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Runtime local já iniciado.\n");
    exit(1);
}
$workersOnly = in_array('--workers-only', $argv, true);
if (! $workersOnly) {
    $test = @stream_socket_client('tcp://127.0.0.1:8876', $errno, $error, 1);
    if ($test) {
        fclose($test);
        fwrite(STDERR, "Porta 8876 ocupada. Não inicia outro servidor. Use --workers-only se o servidor BRN Mail já estiver ativo.\n");
        exit(1);
    }
}
$commands = [
    [PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=inbound', '--tries=1', '--timeout=60', '--memory=128', '--sleep=1'],
    [PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=outbound', '--tries=1', '--timeout=60', '--memory=128', '--sleep=1'],
    [PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=attachments', '--tries=1', '--timeout=45', '--memory=192', '--sleep=1'],
    [PHP_BINARY, 'artisan', 'schedule:work'],
];
if (! $workersOnly) {
    $commands[] = [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port=8876', '--no-reload'];
}
$children = [];
$stop = false;
$code = 0;
pcntl_async_signals(true);
pcntl_signal(SIGINT, function () use (&$stop) {
    $stop = true;
});
pcntl_signal(SIGTERM, function () use (&$stop) {
    $stop = true;
});
try {
    foreach ($commands as $command) {
        $children[] = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
    }
    fwrite(STDOUT, "BRN Mail local: 3 workers separados + scheduler. Externo bloqueado. Ctrl+C encerra somente estes processos.\n");
    while (! $stop) {
        foreach ($children as $child) {
            if (! is_resource($child) || ! proc_get_status($child)['running']) {
                $code = 1;
                $stop = true;
            }
        }
        usleep(200000);
    }
} finally {
    foreach ($children as $child) {
        if (is_resource($child)) {
            proc_terminate($child, SIGTERM);
        }
    }
    foreach ($children as $child) {
        if (is_resource($child)) {
            proc_close($child);
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($code);
