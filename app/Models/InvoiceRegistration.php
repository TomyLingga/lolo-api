<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceRegistration extends Model
{
    protected $guarded = [];
    protected $hidden = ['created_at', 'updated_at'];
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }
}
