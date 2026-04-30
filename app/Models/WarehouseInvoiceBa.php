<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseInvoiceBa extends Model
{
    protected $fillable = ['warehouse_invoice_id', 'ba_id', 'ba_subtotal'];

    protected $casts = [
        'ba_subtotal' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(WarehouseInvoice::class, 'warehouse_invoice_id');
    }

    public function beritaAcara()
    {
        return $this->belongsTo(WarehouseBeritaAcara::class, 'ba_id');
    }
}
