<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MailOutbox extends Model
{
    use HasUuids;

    protected $table = 'mail_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'lease_until' => 'datetime', 'first_attempt_at' => 'datetime'];
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
