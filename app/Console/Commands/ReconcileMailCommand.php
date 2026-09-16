<?php

namespace App\Console\Commands;

use App\Services\ReconcileMail;
use Illuminate\Console\Command;

class ReconcileMailCommand extends Command
{
    protected $signature = 'brnmail:reconcile {--after=} {--limit=50}';

    protected $description = 'Reconcilia uma página de recebidos; respeita gate externo, destinatários conhecidos e cursor. Não envia e-mails.';

    public function handle(ReconcileMail $service): int
    {
        try {
            $this->line(json_encode($service->page($this->option('after'), (int) $this->option('limit'))));

            return 0;
        } catch (\Throwable) {
            $this->error('Reconciliação não concluída. Verifique autorização externa, chave e cursor.');

            return 1;
        }
    }
}
