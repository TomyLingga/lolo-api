<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = [];

    public function freightForwarder()
    {
        return $this->belongsTo(FreightForwarders::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function invoiceRegistrations()
    {
        return $this->hasMany(InvoiceRegistration::class);
    }

    public function taxes()
    {
        return $this->belongsToMany(Tax::class, 'invoice_taxes')
                    ->withPivot('tax_value', 'tax_value_type', 'tax_type', 'calculated_amount')
                    ->withTimestamps();
    }
}
