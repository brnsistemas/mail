<?php

// Local-only bootstrap. Never reads another product's credentials.
declare(strict_types=1);
$root = dirname(__DIR__);
umask(0077);
foreach (['bootstrap/cache', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
    if (! is_dir($root.'/'.$directory)) {
        mkdir($root.'/'.$directory, 0700, true);
    }
}
if (file_exists($root.'/.env')) {
    fwrite(STDOUT, "Configuração existente preservada.\n");
    exit(0);
}
$env = file_get_contents($root.'/.env.example');
$env = str_replace('APP_KEY=', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $env);
$env = str_replace(['local-only-brnmail', 'local-only-root-brnmail', 'local-only-redis-brnmail'], [bin2hex(random_bytes(24)), bin2hex(random_bytes(24)), bin2hex(random_bytes(24))], $env);
$env = str_replace('RESEND_WEBHOOK_SECRET=', 'RESEND_WEBHOOK_SECRET=whsec_'.base64_encode(random_bytes(32)), $env);
file_put_contents($root.'/.env', $env, LOCK_EX);
chmod($root.'/.env', 0600);
fwrite(STDOUT, "Configuração local criada. Segredos não exibidos. Envio externo desativado.\n");
