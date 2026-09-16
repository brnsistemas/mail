<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Models\ProviderSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoReceive extends Command
{
    protected $signature = 'brnmail:demo-receive {mailbox : Endereço .test da caixa local}';

    protected $description = 'Entrega webhook sintético assinado somente no próprio localhost; nenhum e-mail externo.';

    public function handle(): int
    {
        if (! app()->environment('local') || ! config('brnmail.local_demo') || config('brnmail.external_enabled') || config('brnmail.transport') !== 'local'
            || DB::connection()->getDatabaseName() !== 'brnmail' || config('app.url') !== 'http://127.0.0.1:8876') {
            $this->error('Demonstração exige ambiente local isolado e envio externo desligado.');

            return 1;
        }
        $box = Mailbox::where('address', $this->argument('mailbox'))->where('active', true)->first();
        if (! $box || ! str_ends_with($box->address, '.test')) {
            $this->error('Caixa local .test não encontrada.');

            return 1;
        }
        $id = (string) Str::uuid();
        $event = 'msg_local_'.Str::random(24);
        $timestamp = (string) time();
        $data = ['id' => $id, 'from' => 'cliente@example.test', 'to' => [$box->address], 'cc' => [], 'bcc' => [], 'received_for' => [],
            'subject' => '[DEMONSTRAÇÃO LOCAL] Recebimento em '.$box->name,
            'text' => 'Esta mensagem é fictícia. Passou pelo webhook assinado local, fila e roteamento da caixa. Não veio da internet. Você pode responder, arquivar ou testar a busca com segurança.',
            'message_id' => '<'.$id.'@example.test>', 'attachments' => []];
        $payload = json_encode(['type' => 'email.received', 'created_at' => now()->toIso8601String(), 'data' => ['email_id' => $id, 'from' => $data['from'], 'to' => $data['to'], 'cc' => [], 'bcc' => [], 'subject' => $data['subject']]], JSON_THROW_ON_ERROR);
        $key = base64_decode(substr(ProviderSetting::valueFor('webhook_secret') ?? '', 6), true);
        if (! $key) {
            $this->error('Segredo local de teste indisponível.');

            return 1;
        }
        Storage::disk('local')->put('fixtures/'.$id.'.json', json_encode($data, JSON_THROW_ON_ERROR));
        try {
            $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->withHeaders(['svix-id' => $event, 'svix-timestamp' => $timestamp, 'svix-signature' => 'v1,'.base64_encode(hash_hmac('sha256', $event.'.'.$timestamp.'.'.$payload, $key, true))])->withBody($payload, 'application/json')->post('http://127.0.0.1:8876/webhooks/resend');
            if ($response->status() !== 202) {
                throw new \RuntimeException;
            }
            $this->line('webhook_local_http=202 email_simulado='.$id.' envios_externos=0');
            $this->line('O dispatcher/worker processará em até um ciclo local. Atualize a caixa para conferir.');

            return 0;
        } catch (\Throwable) {
            $this->error('Webhook local não confirmado. A fixture foi preservada para diagnóstico.');

            return 1;
        }
    }
}
