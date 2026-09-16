<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderSetting extends Model
{
    protected $fillable = ['api_key', 'webhook_secret', 'test_recipients'];

    protected $hidden = ['api_key', 'webhook_secret', 'test_recipients'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'webhook_secret' => 'encrypted', 'test_recipients' => 'encrypted:array'];
    }

    public static function valueFor(string $key): mixed
    {
        $row = static::find(1);
        $value = $row?->$key;

        return $value ?? config('brnmail.'.(['api_key' => 'resend_key', 'webhook_secret' => 'webhook_secret', 'test_recipients' => 'test_recipients'][$key]));
    }
}
