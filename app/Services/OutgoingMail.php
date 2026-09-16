<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\MailOutbox;
use App\Models\Message;
use App\Models\ProviderSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class OutgoingMail
{
    public function __construct(private Access $access, private ResendGateway $provider) {}

    public function queue(Message $message, User $user, int $version): MailOutbox
    {
        return DB::transaction(function () use ($message, $user, $version) {
            $m = Message::lockForUpdate()->findOrFail($message->id);
            $this->access->mailbox($user, $m->mailbox_id, 'send');
            $existing = MailOutbox::where('message_id', $m->id)->first();
            if ($existing) {
                return $existing;
            }
            abort_unless($m->status === 'draft' && $m->author_id === $user->id && $m->version === $version, 409, 'Rascunho mudou. Atualize antes de enviar.');
            $dest = array_merge($m->recipients['to'] ?? [], $m->recipients['cc'] ?? [], $m->recipients['bcc'] ?? []);
            abort_if(! $dest || ! trim($m->subject) || ! trim($m->body_text), 422, 'Preencha destinatário, assunto e mensagem.');
            abort_if($m->attachments()->where('status', '!=', 'clean')->exists(), 422, 'Anexos precisam ser aprovados pela verificação.');
            Mailbox::whereKey($m->mailbox_id)->lockForUpdate()->first();
            abort_if(MailOutbox::whereHas('message', fn ($q) => $q->where('mailbox_id', $m->mailbox_id))->where('created_at', '>=', now()->startOfDay())->count() >= config('brnmail.daily_limit'), 429, 'Limite diário da caixa.');
            if ($m->reply_source_id) {
                $source = Message::findOrFail($m->reply_source_id);
                abort_unless($source->mailbox_id === $m->mailbox_id && $source->assigned_to === $user->id, 409, 'O responsável pela conversa mudou. Revise antes de enviar.');
            }
            $m->update(['status' => 'queued', 'version' => $m->version + 1]);
            $item = MailOutbox::create(['message_id' => $m->id, 'actor_id' => $user->id, 'available_at' => now()]);
            $this->access->audit($user, 'message.queued', $m->mailbox_id, $m->id);

            return $item;
        });
    }

    public function process(string $id): void
    {
        $item = DB::transaction(function () use ($id) {
            $o = MailOutbox::lockForUpdate()->find($id);
            if (! $o || ! in_array($o->status, ['pending', 'retry', 'sending']) || $o->available_at?->isFuture() || $o->lease_until?->isFuture()) {
                return null;
            }
            if ($o->first_attempt_at && $o->first_attempt_at->lt(now()->subHours(23))) {
                $o->update(['status' => 'uncertain', 'last_error' => 'idempotency_window_elapsed']);
                $o->message->update(['status' => 'uncertain']);

                return null;
            }
            $o->update(['status' => 'sending', 'lease_until' => now()->addSeconds(90), 'lease_token' => (string) Str::uuid(), 'attempts' => $o->attempts + 1, 'first_attempt_at' => $o->first_attempt_at ?? now()]);

            return $o;
        });
        if (! $item) {
            return;
        }
        $m = $item->message;
        $user = User::find($item->actor_id);
        try {
            if (! $user || ! $this->access->allowed($user, $m->mailbox_id, 'send')) {
                throw new RuntimeException('access_revoked');
            }
            $box = $m->mailbox;
            if ($m->reply_source_id && ! Message::whereKey($m->reply_source_id)->where('mailbox_id', $m->mailbox_id)->where('assigned_to', $user->id)->exists()) {
                throw new RuntimeException('reply_reassigned');
            }
            $dest = array_merge($m->recipients['to'] ?? [], $m->recipients['cc'] ?? [], $m->recipients['bcc'] ?? []);
            foreach ($dest as $email) {
                if (DB::table('mail_suppressions')->where('mailbox_id', $box->id)->where('recipient_hash', hash_hmac('sha256', strtolower($email), config('app.key')))->exists()) {
                    throw new RuntimeException('recipient_suppressed');
                }
            }
            $files = [];
            foreach ($m->attachments as $a) {
                if ($a->status !== 'clean') {
                    throw new RuntimeException('attachment_not_clean');
                }
                $bytes = Crypt::decryptString(Storage::disk('local')->get($a->path));
                if (! hash_equals($a->sha256, hash('sha256', $bytes))) {
                    throw new RuntimeException('attachment_integrity');
                }
                $files[] = ['filename' => $a->filename, 'content' => base64_encode($bytes)];
            }
            if (config('brnmail.transport') === 'local') {
                if (! app()->environment(['local', 'testing'])) {
                    throw new RuntimeException('local_transport_forbidden');
                }
                $result = ['id' => (string) Str::uuid()];
                $status = 'simulated';
            } else {
                if ($box->domain->status !== 'verified') {
                    throw new RuntimeException('domain_not_verified');
                }
                $allow = array_map('strtolower', ProviderSetting::valueFor('test_recipients'));
                foreach ($dest as $email) {
                    if (! in_array(strtolower($email), $allow, true)) {
                        throw new RuntimeException('recipient_not_approved');
                    }
                }
                $payload = ['from' => $box->address, 'to' => $m->recipients['to'], 'subject' => $m->subject, 'text' => $m->body_text];
                $payload['tags'] = [['name' => 'brnmail_outbox', 'value' => $item->id]];
                foreach (['cc', 'bcc'] as $k) {
                    if (! empty($m->recipients[$k])) {
                        $payload[$k] = $m->recipients[$k];
                    }
                }
                if ($files) {
                    $payload['attachments'] = $files;
                }
                if ($m->in_reply_to) {
                    $payload['headers'] = ['In-Reply-To' => $m->in_reply_to, 'References' => $m->in_reply_to];
                }
                if (strlen(json_encode($payload)) > 30 * 1024 * 1024) {
                    throw new RuntimeException('encoded_message_too_large');
                }
                $result = $this->provider->request('POST', '/emails', $payload, ['Idempotency-Key' => 'brnmail-'.$item->id]);
                if (! Str::isUuid($result['id'] ?? '')) {
                    throw new RuntimeException('provider_uncertain');
                }
                $status = 'accepted';
            }
            DB::transaction(function () use ($item, $m, $result, $status, $user) {
                $locked = MailOutbox::whereKey($item->id)->lockForUpdate()->first();
                if ($locked->lease_token !== $item->lease_token) {
                    return;
                }
                $locked->update(['status' => 'done', 'lease_until' => null, 'last_error' => null]);
                $m->update(['provider_id' => $result['id'], 'folder' => 'sent', 'status' => $status]);
                $this->access->audit($user, 'message.'.$status, $m->mailbox_id, $m->id);
            });
        } catch (\Throwable $e) {
            $safe = ['access_revoked', 'reply_reassigned', 'recipient_suppressed', 'attachment_not_clean', 'attachment_integrity', 'domain_not_verified', 'recipient_not_approved', 'external_disabled', 'provider_rejected', 'provider_uncertain', 'provider_rate_limited', 'encoded_message_too_large', 'local_transport_forbidden'];
            $code = in_array($e->getMessage(), $safe, true) ? $e->getMessage() : 'processing_failed';
            $retry = in_array($code, ['provider_uncertain', 'provider_rate_limited']) && $item->attempts < 6;
            $state = $retry ? 'retry' : ($code === 'provider_uncertain' ? 'uncertain' : 'failed');
            DB::transaction(function () use ($item, $m, $code, $state, $retry) {
                $locked = MailOutbox::whereKey($item->id)->lockForUpdate()->first();
                if ($locked->lease_token !== $item->lease_token) {
                    return;
                }
                $locked->update(['status' => $state, 'last_error' => $code, 'lease_until' => null, 'available_at' => now()->addSeconds(min(900, 30 * (2 ** $item->attempts)))]);
                $m->update(['status' => $retry ? 'queued' : $state]);
            });
        }
    }
}
