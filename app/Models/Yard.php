<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Yard extends Model
{
    protected $guarded = [];
    protected $hidden = ['created_at', 'updated_at'];
    public function blocks() {
        return $this->hasMany(Block::class);
    }

    public function tariffLolos() {
        return $this->hasMany(TariffLolo::class);
    }

    public function tariffStorages() {
        return $this->hasMany(TariffStorage::class);
    }
}
