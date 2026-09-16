<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ReconcileMail
{
    public function __construct(private ResendGateway $provider) {}

    public function page(?string $after = null, int $limit = 50): array
    {
        if ($after !== null && ! Str::isUuid($after)) {
            throw new RuntimeException('invalid_cursor');
        }
        $query = ['limit' => max(1, min(100, $limit))];
        if ($after) {
            $query['after'] = $after;
        }
        $page = $this->provider->request('GET', '/emails/receiving', $query);
        if (! is_array($page['data'] ?? null) || count($page['data']) > $query['limit'] || ! is_bool($page['has_more'] ?? null)) {
            throw new RuntimeException('provider_list_invalid');
        }
        $created = 0;
        $ignored = 0;
        $last = null;
        foreach ($page['data'] as $data) {
            $id = $data['id'] ?? null;
            if (! Str::isUuid($id)) {
                throw new RuntimeException('provider_list_invalid');
            }
            $last = $id;
            $dest = array_merge($data['to'] ?? [], $data['cc'] ?? [], $data['bcc'] ?? []);
            foreach ($dest as $address) {
                if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('provider_list_invalid');
                }
            }
            $dest = array_map('strtolower', $dest);
            $known = Mailbox::where('active', true)->where('address', 'like', '%@%')->where(fn ($q) => $q->whereIn('address', $dest)->orWhereIn('id', DB::table('mail_aliases')->whereIn('address', $dest)->select('mailbox_id')))->exists();
            if (! $known) {
                $ignored++;

                continue;
            }
            if (WebhookEvent::where('email_id', $id)->where('type', 'email.received')->exists()) {
                continue;
            }
            // Authenticated provider listing, not a fabricated signed HTTP webhook.
            $payload = ['type' => 'email.received', 'source' => 'provider_reconciliation', 'data' => ['email_id' => $id, 'to' => $data['to'] ?? [], 'cc' => $data['cc'] ?? [], 'bcc' => $data['bcc'] ?? []]];
            $event = WebhookEvent::firstOrCreate(['event_id' => 'reconcile_'.$id], ['type' => 'email.received', 'email_id' => $id, 'payload' => $payload, 'body_hash' => hash('sha256', json_encode($payload)), 'available_at' => now()]);
            $created += $event->wasRecentlyCreated ? 1 : 0;
        }
        if ($page['has_more'] && (! $last || $last === $after)) {
            throw new RuntimeException('provider_cursor_stalled');
        }

        return ['created' => $created, 'unmapped_ignored' => $ignored, 'has_more' => $page['has_more'], 'next_after' => $page['has_more'] ? $last : null];
    }
}
