<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['body_html', 'rfc_digest'];

    protected static function booted(): void
    {
        static::saving(function (Message $message) {
            if ($message->isDirty('rfc_message_id') || $message->isDirty('mailbox_id')) {
                $message->rfc_digest = $message->rfc_message_id ? self::rfcDigest($message->mailbox_id, $message->rfc_message_id) : null;
            }
        });
    }

    public static function rfcDigest(int $mailbox, string $id): string
    {
        return hash_hmac('sha256', 'rfc|'.$mailbox.'|'.$id, config('app.key'));
    }

    public static function validRfcMessageId(mixed $id): ?string
    {
        return is_string($id) && strlen($id) <= 1000 && preg_match('/^<[^\x00-\x20\x7f<>]+>$/D', $id) ? $id : null;
    }

    protected function casts(): array
    {
        return ['subject' => 'encrypted', 'sender' => 'encrypted', 'recipients' => 'encrypted:array', 'body_text' => 'encrypted', 'body_html' => 'encrypted', 'reply_to' => 'encrypted', 'rfc_message_id' => 'encrypted', 'in_reply_to' => 'encrypted', 'read_at' => 'datetime', 'provider_event_at' => 'datetime'];
    }

    public function mailbox()
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}
