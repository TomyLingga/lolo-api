<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContainerSize extends Model
{
    protected $guarded = [];

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function tariffLolos()
    {
        return $this->hasMany(TariffLolo::class);
    }

    public function tariffStorages()
    {
        return $this->hasMany(TariffStorage::class);
    }
}
