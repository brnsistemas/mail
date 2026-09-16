<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxGrant extends Model
{
    protected $fillable = ['mailbox_id', 'user_id', 'can_read', 'can_send', 'can_manage', 'view_sensitive'];

    protected function casts(): array
    {
        return ['can_read' => 'boolean', 'can_send' => 'boolean', 'can_manage' => 'boolean', 'view_sensitive' => 'boolean'];
    }
}
