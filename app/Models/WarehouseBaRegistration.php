<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseBaRegistration extends Model
{
    protected $fillable = [
        'ba_id', 'warehouse_registration_id',
        'chamber_name', 'chamber_length_m', 'chamber_width_m',
        'area_m2', 'tariff_per_m2', 'rent_start', 'rent_end', 'subtotal',
    ];

    protected $casts = [
        'area_m2'       => 'decimal:2',
        'tariff_per_m2' => 'decimal:2',
        'subtotal'      => 'decimal:2',
        'rent_start'    => 'date',
        'rent_end'      => 'date',
    ];

    public function beritaAcara()
    {
        return $this->belongsTo(WarehouseBeritaAcara::class, 'ba_id');
    }

    public function registration()
    {
        return $this->belongsTo(WarehouseRegistration::class, 'warehouse_registration_id');
    }
}
