<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mailbox extends Model
{
    protected $fillable = ['mail_domain_id', 'name', 'address', 'sensitive', 'active'];

    protected function casts(): array
    {
        return ['sensitive' => 'boolean', 'active' => 'boolean'];
    }

    public function domain()
    {
        return $this->belongsTo(MailDomain::class, 'mail_domain_id');
    }

    public function grants()
    {
        return $this->hasMany(MailboxGrant::class);
    }
}
