<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseInvoiceTax extends Model
{
    protected $fillable = [
        'warehouse_invoice_id', 'tax_id',
        'tax_name', 'tax_value', 'tax_value_type', 'tax_type', 'calculated_amount',
    ];

    protected $casts = [
        'tax_value'         => 'decimal:2',
        'calculated_amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(WarehouseInvoice::class, 'warehouse_invoice_id');
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }
}
