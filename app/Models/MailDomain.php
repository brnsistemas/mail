<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailDomain extends Model
{
    protected $fillable = ['product_id', 'domain', 'status', 'provider_id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
