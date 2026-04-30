<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $guarded = [];
    protected $hidden = ['created_at', 'updated_at'];
    public function chambers() {
        return $this->hasMany(WarehouseChamber::class);
    }

    public function tariff() {
        return $this->hasMany(WarehouseTariff::class);
    }

    public function beritaAcara() {
        return $this->hasMany(WarehouseBeritaAcara::class);
    }

    public function invoice() {
        return $this->hasMany(WarehouseInvoice::class);
    }
}
