<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'role', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
