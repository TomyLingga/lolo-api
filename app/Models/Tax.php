<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    protected $guarded = [];
    protected $hidden = ['created_at', 'updated_at'];

    public function invoices()
    {
        return $this->belongsToMany(Invoice::class, 'invoice_taxes')
                    ->withPivot('tax_value', 'tax_value_type', 'tax_type', 'calculated_amount')
                    ->withTimestamps();
    }
}
