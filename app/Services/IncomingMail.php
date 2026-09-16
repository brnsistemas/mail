<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class IncomingMail
{
    public function __construct(private ResendGateway $provider, private MessageContent $content) {}

    public function process(string $id): void
    {
        $event = DB::transaction(function () use ($id) {
            $e = WebhookEvent::lockForUpdate()->find($id);
            if (! $e || ! in_array($e->status, ['pending', 'retry', 'processing']) || $e->lease_until?->isFuture() || $e->available_at?->isFuture()) {
                return null;
            }
            $e->update(['status' => 'processing', 'lease_until' => now()->addSeconds(90), 'lease_token' => (string) Str::uuid(), 'attempts' => $e->attempts + 1]);

            return $e;
        });
        if (! $event) {
            return;
        }
        try {
            if ($event->type !== 'email.received') {
                $this->delivery($event);

                return;
            }
            if (config('brnmail.transport') === 'local' && app()->environment(['local', 'testing'])) {
                $path = 'fixtures/'.$event->email_id.'.json';
                if (! Storage::disk('local')->exists($path)) {
                    throw new RuntimeException('local_fixture_missing');
                }
                $data = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
            } else {
                $data = $this->provider->received($event->email_id);
            }
            if (($data['id'] ?? null) !== $event->email_id) {
                throw new RuntimeException('provider_id_mismatch');
            }
            $this->ingest($event, $data);
        } catch (\Throwable $e) {
            $safe = ['local_fixture_missing', 'provider_id_mismatch', 'provider_message_id_mismatch', 'external_disabled', 'provider_uncertain', 'provider_rate_limited', 'provider_rejected', 'forwarded_delivery_needs_review', 'recipient_unknown', 'cross_company_delivery', 'ambiguous_recipient', 'attachment_metadata_limit'];
            $code = in_array($e->getMessage(), $safe, true) ? $e->getMessage() : 'inbound_processing_failed';
            $retry = in_array($code, ['provider_uncertain', 'provider_rate_limited']) && $event->attempts < 6;
            WebhookEvent::whereKey($event->id)->where('lease_token', $event->lease_token)->where('status', '!=', 'done')->update(['status' => $retry ? 'retry' : 'quarantine', 'last_error' => $code, 'lease_until' => null, 'available_at' => now()->addMinutes(2)]);
        }
    }

    public function ingest(WebhookEvent $event, array $data): void
    {
        if (! is_array($data['attachments'] ?? []) || count($data['attachments'] ?? []) > 100) {
            throw new RuntimeException('attachment_metadata_limit');
        }
        $addresses = [];
        foreach (['to', 'cc', 'bcc'] as $key) {
            foreach (($data[$key] ?? []) as $address) {
                if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('ambiguous_recipient');
                }
                $addresses[] = strtolower($address);
            }
        }
        $signed = $event->payload['data'] ?? [];
        $signedAddresses = array_map('strtolower', array_merge($signed['to'] ?? [], $signed['cc'] ?? [], $signed['bcc'] ?? []));
        if (array_diff($addresses, $signedAddresses) || array_diff($signedAddresses, $addresses)) {
            throw new RuntimeException('ambiguous_recipient');
        }
        // Resend also fills received_for on direct deliveries. It comes from
        // Received headers, so it must never add a recipient or choose a mailbox.
        // Matching hints are harmless; any divergent or malformed hint stays closed.
        $receivedFor = $data['received_for'] ?? [];
        if (! is_array($receivedFor)) {
            throw new RuntimeException('forwarded_delivery_needs_review');
        }
        foreach ($receivedFor as $address) {
            if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL) || ! in_array(strtolower($address), $addresses, true)) {
                throw new RuntimeException('forwarded_delivery_needs_review');
            }
        }
        $aliasBoxIds = DB::table('mail_aliases')->whereIn('address', $addresses)->pluck('mailbox_id');
        $boxes = Mailbox::where('active', true)->where(fn ($q) => $q->whereIn('address', $addresses)->orWhereIn('id', $aliasBoxIds))->with('domain.product')->get();
        if ($boxes->isEmpty()) {
            throw new RuntimeException('recipient_unknown');
        }
        if ($boxes->pluck('domain.product.organization_id')->unique()->count() > 1) {
            throw new RuntimeException('cross_company_delivery');
        }
        DB::transaction(function () use ($event, $data, $boxes) {
            $locked = WebhookEvent::whereKey($event->id)->lockForUpdate()->first();
            if ($locked->status === 'done' || ($event->lease_token && $locked->lease_token !== $event->lease_token)) {
                return;
            }
            // Serialize copies for this delivery; sorted locks also avoid opposing lock order.
            Mailbox::whereIn('id', $boxes->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($boxes as $box) {
                if (Message::where('mailbox_id', $box->id)->where('provider_id', $event->email_id)->exists()) {
                    continue;
                }
                $headers = array_change_key_case(is_array($data['headers'] ?? null) ? $data['headers'] : [], CASE_LOWER);
                $reply = Message::validRfcMessageId($headers['in-reply-to'] ?? null) ?? '';
                $parent = $this->replyParent($box, $reply, $headers['references'] ?? null);
                $m = Message::create(['mailbox_id' => $box->id, 'thread_id' => $parent?->thread_id ?? (string) Str::uuid(), 'direction' => 'inbound', 'status' => 'received', 'folder' => 'inbox',
                    'subject' => mb_substr((string) ($data['subject'] ?? '(sem assunto)'), 0, 500), 'sender' => mb_substr((string) ($data['from'] ?? ''), 0, 500),
                    'recipients' => ['to' => [$box->address], 'cc' => [], 'bcc' => []], 'body_text' => $this->content->text($data['text'] ?? null, $data['html'] ?? null),
                    'reply_to' => mb_substr((string) ($data['reply_to'][0] ?? ''), 0, 254), 'rfc_message_id' => mb_substr((string) ($data['message_id'] ?? ''), 0, 1000),
                    'in_reply_to' => $reply, 'provider_id' => $event->email_id]);
                $this->content->index($m);
                $reservedBytes = 0;
                foreach (array_values($data['attachments'] ?? []) as $position => $a) {
                    $allowed = app(Attachments::class)->metadataAllowed((string) ($a['filename'] ?? ''), (string) ($a['content_type'] ?? ''));
                    $size = max(0, (int) ($a['size'] ?? 0));
                    $reason = ! $allowed ? 'format_not_allowed' : (($position >= config('brnmail.attachments_count') || $size > config('brnmail.attachment_max') || $reservedBytes + $size > config('brnmail.attachments_total')) ? 'attachment_limits_exceeded' : null);
                    if ($reason === null) {
                        $reservedBytes += $size;
                    }
                    Attachment::create(['message_id' => $m->id, 'filename' => mb_substr((string) ($a['filename'] ?? 'arquivo'), 0, 180), 'mime' => mb_substr((string) ($a['content_type'] ?? 'application/octet-stream'), 0, 100),
                        'size' => $size, 'provider_id' => Str::isUuid($a['id'] ?? '') ? $a['id'] : null, 'status' => $reason ? 'blocked' : 'unavailable', 'reason' => $reason ?? 'awaiting_attachment_retrieval']);
                }
            }
            $event->update(['status' => 'done', 'lease_until' => null, 'last_error' => null]);
        });
    }

    private function delivery(WebhookEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $locked = WebhookEvent::whereKey($event->id)->lockForUpdate()->first();
            if ($locked->status === 'done' || ($event->lease_token && $locked->lease_token !== $event->lease_token)) {
                return;
            }
            $messages = Message::where('provider_id', $event->email_id)->where('direction', 'outbound')->lockForUpdate()->get();
            if ($messages->isEmpty()) {
                $event->update(['status' => $event->attempts < 30 ? 'retry' : 'quarantine', 'available_at' => now()->addMinutes(2), 'lease_until' => null, 'last_error' => 'awaiting_send_result']);

                return;
            }
            $map = ['email.sent' => 'accepted', 'email.delivered' => 'delivered', 'email.bounced' => 'bounced', 'email.complained' => 'complained', 'email.failed' => 'failed', 'email.delivery_delayed' => 'delayed'];
            $rank = ['accepted' => 1, 'delayed' => 2, 'delivered' => 3, 'failed' => 4, 'bounced' => 5, 'complained' => 6];
            $target = $map[$event->type];
            foreach ($messages as $m) {
                $changes = [];
                if ($messageId = Message::validRfcMessageId($event->payload['data']['message_id'] ?? null)) {
                    if ($m->rfc_message_id && $m->rfc_message_id !== $messageId) {
                        throw new RuntimeException('provider_message_id_mismatch');
                    }
                    $changes['rfc_message_id'] = $messageId;
                }
                if (($rank[$target] ?? 0) >= ($rank[$m->status] ?? 0)) {
                    $changes += ['status' => $target, 'provider_event_at' => now()];
                }
                if ($changes) {
                    $m->update($changes);
                }
                if (in_array($target, ['bounced', 'complained'])) {
                    foreach (array_merge(...array_values($m->recipients)) as $email) {
                        DB::table('mail_suppressions')->updateOrInsert(['mailbox_id' => $m->mailbox_id, 'recipient_hash' => hash_hmac('sha256', strtolower($email), config('app.key'))], ['reason' => $target, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            $event->update(['status' => 'done', 'lease_until' => null, 'last_error' => null]);
        });
    }

    private function replyParent(Mailbox $box, string $reply, mixed $references): ?Message
    {
        if ($reply) {
            $direct = Message::where('mailbox_id', $box->id)->where('rfc_digest', Message::rfcDigest($box->id, $reply))->get();
            if ($direct->isNotEmpty()) {
                return $direct->pluck('thread_id')->unique()->count() === 1 ? $direct->first() : null;
            }
        }
        // A reply can arrive before the sent event stores its Message-ID.
        // Only unambiguous ancestors already stored in this mailbox can link it.
        if (! is_string($references) || strlen($references) > 8000) {
            return null;
        }
        $ids = preg_split('/\s+/', trim($references), -1, PREG_SPLIT_NO_EMPTY);
        if (count($ids) > 50 || ! $ids) {
            return null;
        }
        foreach ($ids as $id) {
            if (! Message::validRfcMessageId($id)) {
                return null;
            }
        }
        $digests = array_map(fn ($id) => Message::rfcDigest($box->id, $id), $ids);
        $parents = Message::where('mailbox_id', $box->id)->whereIn('rfc_digest', $digests)->get();

        return $parents->pluck('thread_id')->unique()->count() === 1 ? $parents->first() : null;
    }
}
