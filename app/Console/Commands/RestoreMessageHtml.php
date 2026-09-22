<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RestoreMessageHtml as Restore;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RestoreMessageHtml extends Command
{
    protected $signature = 'brnmail:restore-html {message* : IDs explícitos das mensagens} {--operator= : ID da pessoa autorizada a ler as caixas}';

    protected $description = 'Recupera somente o HTML de mensagens específicas; não reenvia e-mails nem altera permissões.';

    public function handle(Restore $restore): int
    {
        $ids = array_values(array_unique($this->argument('message')));
        $operator = User::find($this->option('operator'));
        if (! $operator || ! $operator->active || count($ids) > 20 || collect($ids)->contains(fn ($id) => ! Str::isUuid($id))) {
            $this->error('Operador ou lista de mensagens inválida.');

            return self::FAILURE;
        }
        foreach ($ids as $id) {
            try {
                $changed = $restore->restore($operator, $id);
                $this->line($id.': '.($changed ? 'HTML recuperado com proteção.' : 'HTML já existente, preservado.'));
            } catch (\Throwable) {
                // Provider errors can contain secrets. Never emit exception text or response bodies.
                $this->error($id.': recuperação não concluída; confira acesso e disponibilidade do original.');

                return self::FAILURE;
            }
            usleep(600000);
        }

        return self::SUCCESS;
    }
}
