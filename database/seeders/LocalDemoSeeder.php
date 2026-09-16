<?php

namespace Database\Seeders;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\MessageContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! str_starts_with(config('database.connections.mysql.database'), 'brnmail')) {
            throw new \RuntimeException('Demo restrita ao ambiente local BRN Mail.');
        }
        $o = Organization::firstOrCreate(['name' => 'BRN · Demonstração local']);
        $p = Product::firstOrCreate(['organization_id' => $o->id, 'name' => 'Produto Alpha']);
        $d = MailDomain::firstOrCreate(['domain' => 'brnmail.test'], ['product_id' => $p->id, 'status' => 'local']);
        $u = User::firstOrCreate(['email' => 'demo@brnmail.test'], ['name' => 'Administrador · Local', 'password' => 'Demo-local-only!2026']);
        $u->master = true;
        $u->save();
        Membership::firstOrCreate(['organization_id' => $o->id, 'user_id' => $u->id], ['role' => 'admin']);
        foreach (['comunicacao' => 'Comunicação', 'ceo' => 'CEO', 'marketing' => 'Marketing', 'comercial' => 'Comercial', 'suporte' => 'Suporte', 'newsletter' => 'Newsletter'] as $local => $name) {
            $box = Mailbox::firstOrCreate(['address' => $local.'@brnmail.test'], ['mail_domain_id' => $d->id, 'name' => $name, 'sensitive' => $local === 'ceo']);
            MailboxGrant::firstOrCreate(['mailbox_id' => $box->id, 'user_id' => $u->id], ['can_read' => true, 'can_send' => true, 'can_manage' => true, 'view_sensitive' => true]);
        }
        $box = Mailbox::where('address', 'comunicacao@brnmail.test')->first();
        if (! Message::where('mailbox_id', $box->id)->exists()) {
            foreach ([
                ['Ana · Equipe de criação <ana@exemplo.test>', 'Bem-vindo à sua central de e-mails', 'Olá,\n\nEste é o ambiente local do BRN Mail. As mensagens de demonstração são fictícias, mas os rascunhos, permissões e ações já são gravados no banco local.\n\nVocê pode escrever, organizar e testar suas caixas com tranquilidade. Nenhum e-mail externo será enviado.\n\nEquipe BRN'],
                ['Operação <operacao@exemplo.test>', 'Uma caixa para cada conversa', 'Comunicação, comercial e suporte têm permissões próprias. O acesso à correspondência sensível do CEO depende de uma concessão explícita.'],
                ['Segurança <seguranca@exemplo.test>', 'Sua conta, protegida por dois fatores', 'Depois da senha, o código do autenticador confirma sua identidade. Guarde seus códigos de recuperação em um cofre separado.'],
                ['Financeiro <financeiro@exemplo.test>', 'Documentos para a próxima reunião', 'Os anexos de imagem e documento passam por validação e antivírus. Se o scanner não responder, continuam bloqueados. Vídeos não são aceitos nesta fase.'],
                ['Produto <produto@exemplo.test>', 'Tudo pronto para começar os testes locais', 'Explore as caixas e escreva um rascunho. O processamento local permite verificar a fila sem usar a conta real do Resend.'],
            ] as $i => $entry) {
                $m = Message::create(['mailbox_id' => $box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received', 'sender' => $entry[0], 'subject' => $entry[1], 'body_text' => str_replace('\\n', "\n", $entry[2]), 'recipients' => ['to' => [$box->address], 'cc' => [], 'bcc' => []], 'created_at' => now()->subMinutes($i * 17)]);
                app(MessageContent::class)->index($m);
            }
        }
    }
}
