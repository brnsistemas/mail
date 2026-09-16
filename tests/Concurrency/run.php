<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Multi-process proof against the isolated MySQL QA database, never brnmail live/demo.
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=mysql');
putenv('DB_DATABASE=brnmail_test');
putenv('DB_PORT=33461');
putenv('DB_HOST=127.0.0.1');
putenv('BRNMAIL_TRANSPORT=local');
putenv('BRNMAIL_EXTERNAL_ENABLED=false');
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'brnmail_test' || config('database.default') !== 'mysql') {
    exit(2);
}
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
use App\Models\MailOutbox;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\OutgoingMail;
use Illuminate\Support\Str;

if (($argv[1] ?? '') === 'worker') {
    try {
        $user = User::findOrFail((int) $argv[2]);
        $ids = explode(',', $argv[3]);
        foreach ($ids as $id) {
            $m = Message::findOrFail($id);
            $o = app(OutgoingMail::class)->queue($m, $user, 1);
            app(OutgoingMail::class)->process($o->id);
        }
        echo "worker_ok\n";
        exit(0);
    } catch (Throwable $e) {
        echo 'worker_failed_class='.get_class($e)."\n";
        exit(1);
    }
}
$start = microtime(true);
$o = null;
$u = null;
try {
    $suffix = bin2hex(random_bytes(6));
    $o = Organization::create(['name' => 'Concorrência '.$suffix]);
    $u = User::create(['name' => 'QA concorrente', 'email' => $suffix.'@concurrency.test', 'password' => bin2hex(random_bytes(30))]);
    Membership::create(['organization_id' => $o->id, 'user_id' => $u->id, 'active' => true]);
    $p = Product::create(['organization_id' => $o->id, 'name' => 'Concorrência']);
    $d = MailDomain::create(['product_id' => $p->id, 'domain' => $suffix.'.test']);
    $b = Mailbox::create(['mail_domain_id' => $d->id, 'name' => 'QA', 'address' => 'qa@'.$d->domain]);
    MailboxGrant::create(['mailbox_id' => $b->id, 'user_id' => $u->id, 'can_read' => true, 'can_send' => true]);
    $ids = [];
    for ($i = 0; $i < 32; $i++) {
        $ids[] = Message::create(['mailbox_id' => $b->id, 'author_id' => $u->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'outbound', 'status' => 'draft', 'folder' => 'drafts', 'sender' => $b->address, 'subject' => 'Concorrência '.$i, 'body_text' => 'Mensagem sintética. Não envia pela rede.', 'recipients' => ['to' => ['fake@example.test'], 'cc' => [], 'bcc' => []]])->id;
    }
    $processes = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $u->id, implode(',', $ids)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($proc)) {
            throw new RuntimeException('worker_start_failed');
        } fclose($pipes[0]);
        $processes[] = [$proc, $pipes];
    }
    foreach ($processes as [$proc,$pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0 || trim($out) !== 'worker_ok') {
            throw new RuntimeException('concurrent_worker_failed');
        }
    }
    $rows = MailOutbox::whereIn('message_id', $ids)->get();
    if ($rows->count() !== 32 || $rows->where('status', 'done')->count() !== 32 || $rows->sum('attempts') !== 32 || Message::whereIn('id', $ids)->where('status', 'simulated')->count() !== 32) {
        throw new RuntimeException('concurrency_invariant_failed');
    }
    echo json_encode(['workers' => 4, 'send_attempts' => 128, 'unique_intents' => 32, 'processed_once' => 32, 'external_sends' => 0, 'seconds' => round(microtime(true) - $start, 2)])."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'concurrency_failed='.get_class($e)."\n");
    exit(1);
} finally {
    $o?->delete();
    $u?->delete();
}
