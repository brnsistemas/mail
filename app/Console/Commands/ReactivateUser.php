<?php

namespace App\Console\Commands;

use App\Services\AccountReactivation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReactivateUser extends Command
{
    protected $signature = 'brnmail:reactivate-user {email} {--operator=} {--organization=} {--output=} {--revoke-and-reinvite}';

    protected $description = 'Revoga credenciais de um colaborador e grava um convite privado de nova ativação; não envia e-mail.';

    public function handle(AccountReactivation $service): int
    {
        $path = (string) $this->option('output');
        $parent = realpath(dirname($path));
        if (! $this->option('revoke-and-reinvite') || ! filter_var($this->argument('email'), FILTER_VALIDATE_EMAIL)
            || ! ctype_digit((string) $this->option('operator')) || ! ctype_digit((string) $this->option('organization'))
            || ! str_starts_with($path, '/') || ! str_ends_with($path, '.html') || ! $parent
            || (fileperms($parent) & 0077) !== 0 || is_link($path) || file_exists($path)) {
            $this->error('Informe operador, empresa existente e um novo arquivo .html em diretório privado (0700), com --revoke-and-reinvite.');

            return self::FAILURE;
        }
        $oldMask = umask(0077);
        $file = @fopen($path, 'x');
        umask($oldMask);
        if (! $file) {
            $this->error('Não foi possível reservar o arquivo privado. Nada foi alterado.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($service, $file) {
                [$invite, $token] = $service->issue((int) $this->option('operator'), (string) $this->argument('email'), (int) $this->option('organization'));
                $link = url('/invite/'.$invite->id).'#'.$token;
                $html = '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="referrer" content="no-referrer"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; base-uri \'none\'"><title>Convite privado BRN Mail</title><h1>Nova ativação do BRN Mail</h1><p>Compartilhe este arquivo ou copie o endereço do botão somente pelo canal privado combinado com o titular. Não publique nem fotografe esta página.</p><p>O próprio colaborador escolhe e confirma a senha e configura um novo autenticador. O link expira em 48 horas e só pode ser usado uma vez.</p><p><a rel="noreferrer" href="'.htmlspecialchars($link, ENT_QUOTES, 'UTF-8').'">Definir minha senha e configurar o 2FA</a></p></html>';
                if (fwrite($file, $html) !== strlen($html) || ! fflush($file)) {
                    throw new \RuntimeException('private_invite_write_failed');
                }
            });
        } catch (Throwable) {
            fclose($file);
            unlink($path);
            $this->error('Operação recusada. Confira identidade, vínculo e convite pendente. Nenhum token foi exibido.');

            return self::FAILURE;
        }
        fclose($file);
        $this->info('Acesso anterior revogado. Convite gravado no arquivo privado indicado. Senha e 2FA serão definidos pelo titular; nenhum e-mail foi enviado.');

        return self::SUCCESS;
    }
}
