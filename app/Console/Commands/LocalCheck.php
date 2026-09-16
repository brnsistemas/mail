<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class LocalCheck extends Command
{
    protected $signature = 'brnmail:local-check';

    protected $description = 'Confere somente o ambiente local, sem imprimir credenciais.';

    public function handle(): int
    {
        $guards = app()->environment('local') && ! config('brnmail.external_enabled') && config('brnmail.transport') === 'local'
            && config('database.default') === 'mysql' && config('database.connections.mysql.host') === '127.0.0.1'
            && (string) config('database.connections.mysql.port') === '33461' && config('database.connections.mysql.database') === 'brnmail';
        if (! $guards) {
            $this->error('Contrato local inválido. Nenhuma ação executada.');

            return 1;
        }
        try {
            $db = DB::selectOne('SELECT DATABASE() AS db, VERSION() AS version');
            if ($db->db !== 'brnmail' || ! str_starts_with($db->version, '8.4.')) {
                throw new \RuntimeException;
            }
            Redis::connection()->ping();
            $socket = @stream_socket_client('tcp://127.0.0.1:13310', $errno, $error, 3);
            if (! $socket) {
                throw new \RuntimeException;
            }
            stream_set_timeout($socket, 5);
            fwrite($socket, "zPING\0");
            $reply = stream_get_line($socket, 100, "\0");
            fclose($socket);
            if (trim($reply ?: '') !== 'PONG') {
                throw new \RuntimeException;
            }
            $this->line('mysql='.$db->version.' database=brnmail redis=OK scanner=PONG external=disabled');
            $this->line('Serviços acessíveis. Este check não comprova worker, scheduler ou entrega externa.');

            return 0;
        } catch (\Throwable) {
            $this->error('Dependência local indisponível. Nenhum segredo foi exibido.');

            return 1;
        }
    }
}
