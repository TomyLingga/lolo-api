<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseInvoice extends Model
{
    protected $fillable = [
        'freight_forwarder_id', 'warehouse_id', 'invoice_number',
        'spk_name', 'spk_number', 'spk_date', 'po_number',
        'invoice_date', 'due_date',
        'subtotal', 'grand_total',
        'bank_name', 'swift_code', 'bank_account_name', 'bank_account_number',
        'signatory_name', 'signatory_position',
        'status', 'is_active', 'generated_by',
    ];

    protected $casts = [
        'spk_date'     => 'date',
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'subtotal'     => 'decimal:2',
        'grand_total'  => 'decimal:2',
        'is_active'    => 'boolean',
    ];

    public function freightForwarder()
    {
        return $this->belongsTo(FreightForwarders::class, 'freight_forwarder_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    // BA yang dimasukkan ke invoice ini
    public function invoiceBas()
    {
        return $this->hasMany(WarehouseInvoiceBa::class, 'warehouse_invoice_id')
                    ->orderBy('id', 'asc');
    }

    // Pajak
    public function taxes()
    {
        return $this->hasMany(WarehouseInvoiceTax::class, 'warehouse_invoice_id')
                    ->orderBy('id', 'asc');
    }
}
