<?php

namespace App\Console\Commands;

use App\Services\InvitationMailbox;
use Illuminate\Console\Command;
use Throwable;

final class RepairInvitedMailbox extends Command
{
    protected $signature = 'brnmail:repair-invited-mailbox {email} {--operator=} {--organization=} {--provision-personal-mailbox}';

    protected $description = 'Regulariza a própria caixa de um colaborador com convite já aceito, sem redefinir senha ou 2FA.';

    public function handle(InvitationMailbox $service): int
    {
        if (! $this->option('provision-personal-mailbox') || ! filter_var($this->argument('email'), FILTER_VALIDATE_EMAIL)
            || ! ctype_digit((string) $this->option('operator')) || ! ctype_digit((string) $this->option('organization'))) {
            $this->error('Informe identidade, operador master, empresa e --provision-personal-mailbox.');

            return self::FAILURE;
        }
        try {
            $box = $service->repair((int) $this->option('operator'), $this->argument('email'), (int) $this->option('organization'));
        } catch (Throwable) {
            $this->error('Correção recusada. Confira convite aceito, vínculo, domínio e ausência de caixa em conflito. Nada foi alterado.');

            return self::FAILURE;
        }
        $this->info('Caixa individual disponível: '.$box->address.'. Leitura e envio concedidos. Senha e autenticador preservados.');

        return self::SUCCESS;
    }
}
