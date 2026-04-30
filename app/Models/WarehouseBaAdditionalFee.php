<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseBaAdditionalFee extends Model
{
    protected $fillable = ['ba_id', 'fee_name', 'fee_amount'];

    protected $casts = [
        'fee_amount' => 'decimal:2',
    ];

    public function beritaAcara()
    {
        return $this->belongsTo(WarehouseBeritaAcara::class, 'ba_id');
    }
}
