<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\MailOutbox;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ResendEventScope
{
    public function accepts(array $event): bool
    {
        $data = $event['data'];
        if ($event['type'] === 'email.received') {
            $addresses = [];
            foreach (['to', 'cc', 'bcc'] as $field) {
                $values = $data[$field] ?? [];
                abort_unless(is_array($values) && array_is_list($values), 422);
                foreach ($values as $address) {
                    abort_unless(is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL), 422);
                    $addresses[] = strtolower($address);
                }
            }

            return Mailbox::where('active', true)->where(fn ($query) => $query
                ->whereIn('address', $addresses)
                ->orWhereIn('id', DB::table('mail_aliases')->whereIn('address', $addresses)->select('mailbox_id')))->exists();
        }

        if (Message::where('direction', 'outbound')->where('provider_id', $data['email_id'])->exists()) {
            return true;
        }

        // A signed event can arrive before the send response has been committed.
        // Correlate it with our attempted send, never with a shared sender domain.
        $tags = $data['tags'] ?? [];
        $outboxId = is_array($tags) ? ($tags['brnmail_outbox'] ?? null) : null;
        if (! is_string($outboxId) || ! Str::isUuid($outboxId)) {
            return false;
        }
        $outbox = MailOutbox::with('message.mailbox')->find($outboxId);
        $message = $outbox?->message;

        return $outbox?->first_attempt_at !== null
            && $message?->direction === 'outbound'
            && ($message->provider_id === null || $message->provider_id === $data['email_id'])
            && ($data['from'] ?? null) === $message->mailbox->address;
    }
}
