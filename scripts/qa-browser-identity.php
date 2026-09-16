<?php

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
echo json_encode(['email' => $user->email, 'password' => $password, 'box' => $box->id, 'secondary_box' => $secondary?->id]);
