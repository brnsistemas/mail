<?php

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Called by the browser QA harness, never by HTTP. Synthetic identity only.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'brnmail' || config('brnmail.external_enabled')) {
    exit(2);
}
$box = Mailbox::where('address', 'comunicacao@brnmail.test')->firstOrFail();
$password = bin2hex(random_bytes(20));
$user = User::firstOrCreate(['email' => 'browser-qa@brnmail.test'], ['name' => 'QA local · identidade fictícia', 'password' => $password]);
$user->password = $password;
$user->totp_secret = null;
$user->totp_confirmed_at = null;
$user->totp_last_step = null;
$user->recovery_hashes = null;
$user->master = true;
$user->active = true;
$user->security_version++;
$user->save();
Membership::updateOrCreate(['user_id' => $user->id, 'organization_id' => $box->domain->product->organization_id], ['active' => true, 'role' => 'admin']);
foreach (Mailbox::where('mail_domain_id', $box->mail_domain_id)->get() as $b) {
    MailboxGrant::updateOrCreate(['user_id' => $user->id, 'mailbox_id' => $b->id], ['can_read' => true, 'can_send' => true, 'view_sensitive' => true]);
}
// The harness consumes this pipe in memory; never writes credentials to reports.
$secondary = Mailbox::where('address', 'comunicacao@workspace.test')->first();
if ($secondary) {
    Membership::updateOrCreate(['user_id' => $user->id, 'organization_id' => $secondary->domain->product->organization_id], ['active' => true, 'role' => 'member']);
    MailboxGrant::updateOrCreate(['user_id' => $user->id, 'mailbox_id' => $secondary->id], ['can_read' => true, 'can_send' => true]);
}
$htmlMessage = Message::firstOrCreate(['provider_id' => 'browser-html-synthetic'], [
    'mailbox_id' => $box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received',
    'subject' => 'QA HTML e Spam', 'sender' => 'sender@example.test', 'recipients' => ['to' => [$box->address]], 'body_text' => 'Alternativa sintética em texto',
    'body_html' => '<h1>Convite de demonstração</h1><table style="width:100%;background-color:#f4f5f7"><tr><td style="padding:24px"><p>Mensagem sintética para conferir o leitor.</p><a href="http://127.0.0.1:8876/login?qa=html-link" style="background-color:#175cd3;color:#ffffff;padding:12px 20px;border-radius:6px;display:inline-block;text-decoration:none">Aceitar convite</a><img src="http://127.0.0.1:8876/qa-forbidden-tracker"><script>parent.document.body.dataset.compromised="yes"</script><form action="/qa-forbidden-form"><input name="password"></form></td></tr></table>',
]);
echo json_encode(['html_message' => $htmlMessage->id, 'email' => $user->email, 'password' => $password, 'box' => $box->id, 'secondary_box' => $secondary?->id]);
