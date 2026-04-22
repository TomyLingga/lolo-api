<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TariffLolo extends Model
{
    protected $guarded = [];

    public function yard()
    {
        return $this->belongsTo(Yard::class);
    }

    public function containerSize()
    {
        return $this->belongsTo(ContainerSize::class);
    }

    public function containerType()
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function cargoStatus()
    {
        return $this->belongsTo(CargoStatus::class);
    }
}
