<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseBeritaAcara extends Model
{
    protected $fillable = [
        'freight_forwarder_id', 'warehouse_id', 'ba_number', 'ba_date',
        'subtotal',
        'bank_name', 'bank_account_name', 'bank_account_number',
        'signer_smnt_name', 'signer_smnt_position',
        'signer_ff_name', 'signer_ff_position',
        'approver_ff_name', 'approver_ff_position',
        'invoiced', 'is_active', 'created_by',
    ];

    protected $casts = [
        'ba_date'   => 'date',
        'subtotal'  => 'decimal:2',
        'invoiced'  => 'boolean',
        'is_active' => 'boolean',
    ];

    public function freightForwarder()
    {
        return $this->belongsTo(FreightForwarders::class, 'freight_forwarder_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Pivot ke registrasi
    public function baRegistrations()
    {
        return $this->hasMany(WarehouseBaRegistration::class, 'ba_id')
                    ->orderBy('id', 'asc');
    }

    // Biaya tambahan
    public function additionalFees()
    {
        return $this->hasMany(WarehouseBaAdditionalFee::class, 'ba_id')
                    ->orderBy('id', 'asc');
    }

    // Invoice yang memakai BA ini
    public function invoiceBas()
    {
        return $this->hasMany(WarehouseInvoiceBa::class, 'ba_id');
    }

    // Kalkulasi subtotal: sum chamber + sum additional fees
    public function calculateSubtotal(): float
    {
        $chamberTotal = $this->baRegistrations->sum('subtotal');
        $feesTotal    = $this->additionalFees->sum('fee_amount');
        return (float) $chamberTotal + (float) $feesTotal;
    }
}
