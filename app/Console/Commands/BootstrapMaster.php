<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Access;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BootstrapMaster extends Command
{
    protected $signature = 'brnmail:bootstrap-master';

    protected $description = 'Cadastra somente o primeiro master do BRN Mail; senha oculta e 2FA pendente. Não redefine contas.';

    private const LOCK = 'brnmail.first-master';

    public function handle(Access $access): int
    {
        if (! $this->input->isInteractive() || ! $this->targetAllowed()) {
            $this->error('Cadastro recusado: exige console interativo e configuração isolada de primeiro acesso do BRN Mail.');

            return self::FAILURE;
        }

        $connection = DB::connection('mysql');
        $locked = false;
        $password = $confirmation = null;
        try {
            // Never infer compatibility from a binary named mysqld (it may be MariaDB).
            $version = (string) $connection->selectOne('SELECT VERSION() AS version')->version;
            if (! str_starts_with($version, '8.4.') || stripos($version, 'mariadb') !== false) {
                $this->error('Cadastro recusado: esta implantação exige MySQL 8.4.');

                return self::FAILURE;
            }
            $locked = (int) $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::LOCK])->acquired === 1;
            if (! $locked || User::exists()) {
                $this->error('Cadastro recusado: já existem usuários ou outro cadastro inicial está em andamento. Nada foi substituído.');

                return self::FAILURE;
            }

            $this->line('Alvo: BRN Mail. Este comando não cria caixas, não altera DNS e não envia e-mails.');
            $name = trim((string) $this->ask('Nome do primeiro administrador'));
            $email = strtolower(trim((string) $this->ask('E-mail de login do master')));
            if (Validator::make(['name' => $name, 'email' => $email], [
                'name' => ['required', 'string', 'min:2', 'max:100'],
                'email' => ['required', 'email:rfc', 'max:254'],
            ])->fails()) {
                $this->error('Nome ou e-mail inválido. Nenhuma conta foi criada.');

                return self::FAILURE;
            }

            // No command-line/env password; refuse terminals that cannot hide input.
            $password = $this->secret('Senha nova (14 a 72 bytes; não será exibida)', false);
            $confirmation = $this->secret('Confirme a senha nova', false);
            if (! is_string($password) || ! is_string($confirmation)
                || strlen($password) < 14 || strlen($password) > 72
                || str_contains($password, "\0") || trim($password) === ''
                || ! hash_equals($password, $confirmation)) {
                $this->error('Senha fora dos limites ou confirmação diferente. Nenhuma conta foi criada.');

                return self::FAILURE;
            }
            if (! $this->confirm('Criar este primeiro master? O segundo fator continuará obrigatório', false)) {
                $this->line('Cadastro cancelado. Nenhuma conta foi criada.');

                return self::SUCCESS;
            }

            $connection->transaction(function () use ($name, $email, &$password, $access): void {
                if (User::query()->lockForUpdate()->first() !== null) {
                    throw new \RuntimeException('initial_identity_exists');
                }
                $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
                $password = null;
                $user->master = true;
                $user->active = true;
                $user->save();
                // Audit and identity must succeed together. No password, email or token in audit.
                $access->audit($user, 'identity.first_master_created', null, (string) $user->id);
            });
            $this->info('Master criado. Entre no painel HTTPS e configure o autenticador e os códigos de recuperação.');
            $this->line('Nenhuma caixa foi criada ou concedida. Convites ainda são compartilhados por link manual.');

            return self::SUCCESS;
        } catch (\Throwable) {
            // Console exception traces can expose arguments; keep failures sanitized.
            $this->error('Cadastro não concluído. Verifique banco, migrations e entrada oculta; nenhuma conta existente foi alterada.');

            return self::FAILURE;
        } finally {
            $password = $confirmation = null;
            if ($locked) {
                try {
                    $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK]);
                } catch (\Throwable) {
                    // Connection close also releases the connection-scoped advisory lock.
                    $connection->disconnect();
                }
            }
        }
    }

    private function targetAllowed(): bool
    {
        $testing = app()->environment('testing');
        $expectedPort = $testing ? 33461 : filter_var(config('brnmail.bootstrap_db_port', 33461), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
        $bootstrapUrl = rtrim((string) config('brnmail.bootstrap_url'), '/');
        $url = parse_url($bootstrapUrl);
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return ($testing || app()->environment('production'))
            && ! config('app.debug')
            && ! config('brnmail.local_demo')
            && ! config('brnmail.external_enabled')
            && config('brnmail.transport') === 'resend'
            && is_string($key) && Encrypter::supported($key, config('app.cipher'))
            && filter_var($bootstrapUrl, FILTER_VALIDATE_URL) !== false
            && is_array($url) && ($url['scheme'] ?? '') === 'https'
            && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment'])
            && empty($url['path'])
            && rtrim((string) config('app.url'), '/') === $bootstrapUrl
            && config('database.default') === 'mysql'
            && config('database.connections.mysql.driver') === 'mysql'
            && ! config('database.connections.mysql.url')
            && ! config('database.connections.mysql.unix_socket')
            && ! config('database.connections.mysql.read')
            && ! config('database.connections.mysql.write')
            && config('database.connections.mysql.host') === '127.0.0.1'
            && $expectedPort !== false
            && (string) config('database.connections.mysql.port') === (string) $expectedPort
            && config('database.connections.mysql.database') === ($testing ? 'brnmail_test' : 'brnmail');
    }
}
