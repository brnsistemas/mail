<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RestoreMessageHtml
{
    public function __construct(private Access $access, private ResendGateway $provider, private MailHtml $html) {}

    public function restore(User $operator, string $id): bool
    {
        $message = Message::whereIn('mailbox_id', $this->access->mailboxes($operator)->select('id'))
            ->where('direction', 'inbound')->whereNotNull('provider_id')->findOrFail($id);
        if ($message->body_html) {
            return false;
        }
        $data = $this->provider->received($message->provider_id);
        if (($data['id'] ?? null) !== $message->provider_id
            || app(MessageContent::class)->text($data['text'] ?? null, $data['html'] ?? null) !== $message->body_text) {
            throw new RuntimeException('original_message_mismatch');
        }
        $safe = $this->html->sanitize($data['html'] ?? null);
        if ($safe === null) {
            throw new RuntimeException('original_html_unavailable');
        }

        return DB::transaction(function () use ($operator, $message, $safe) {
            $locked = Message::lockForUpdate()->findOrFail($message->id);
            abort_unless($this->access->allowed($operator->fresh(), $locked->mailbox_id), 403);
            if ($locked->body_html) {
                return false;
            }
            if ($locked->provider_id !== $message->provider_id || $locked->body_text !== $message->body_text || $locked->direction !== 'inbound') {
                throw new RuntimeException('original_message_changed');
            }
            $locked->timestamps = false;
            $locked->body_html = $safe;
            $locked->save();
            $this->access->audit($operator, 'message.html_restored', $locked->mailbox_id, $locked->id);

            return true;
        });
    }
}
