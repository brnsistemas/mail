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

class LocalWorkspaceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'brnmail'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || (string) config('database.connections.mysql.port') !== '33461'
            || config('database.connections.mysql.url') || config('brnmail.external_enabled')) {
            throw new \RuntimeException('Demonstração multiempresa restrita ao MySQL local isolado.');
        }
        // Fictitious companies/addresses only. Never change the existing password or MFA.
        $user = User::where('email', 'demo@brnmail.test')->firstOrFail();
        $organization = Organization::firstOrCreate(['name' => 'Produto Beta · Demonstração local']);
        $product = Product::firstOrCreate(['organization_id' => $organization->id, 'name' => 'Produto Beta']);
        $domain = MailDomain::firstOrCreate(['domain' => 'workspace.test'], ['product_id' => $product->id, 'status' => 'local']);
        abort_unless($domain->product_id === $product->id, 409);
        Membership::firstOrCreate(['organization_id' => $organization->id, 'user_id' => $user->id], ['role' => 'admin']);
        foreach (['comunicacao' => 'Comunicação', 'comercial' => 'Comercial'] as $local => $name) {
            $box = Mailbox::firstOrCreate(['address' => $local.'@workspace.test'], ['mail_domain_id' => $domain->id, 'name' => $name]);
            abort_unless($box->mail_domain_id === $domain->id, 409);
            MailboxGrant::firstOrCreate(['mailbox_id' => $box->id, 'user_id' => $user->id], ['can_read' => true, 'can_send' => true, 'can_manage' => true, 'view_sensitive' => true]);
            if (! Message::where('mailbox_id', $box->id)->exists()) {
                $message = Message::create(['mailbox_id' => $box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received', 'sender' => 'Equipe CRM <equipe@exemplo.test>', 'subject' => 'Uma empresa diferente. A mesma conta.', 'body_text' => "Esta é uma mensagem fictícia do Produto Beta.\n\nAs mensagens desta caixa não aparecem no Produto Alpha. Ao escrever, confira o remetente @workspace.test. Nada será enviado para fora do localhost.", 'recipients' => ['to' => [$box->address], 'cc' => [], 'bcc' => []]]);
                app(MessageContent::class)->index($message);
            }
        }
    }
}
