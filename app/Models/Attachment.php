<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['filename' => 'encrypted', 'scan_attempted_at' => 'datetime', 'scan_available_at' => 'datetime', 'scan_lease_until' => 'datetime', 'scan_attempts' => 'integer'];
    }

    public function scopeDueForScan(Builder $query): Builder
    {
        return $query->where('status', 'quarantine')->whereNotNull('path')
            ->where(fn ($q) => $q->whereNull('scan_available_at')->orWhere('scan_available_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('scan_lease_until')->orWhere('scan_lease_until', '<=', now()));
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
