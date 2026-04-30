<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseTariff extends Model
{
    protected $guarded = [];
    protected $hidden = ['created_at', 'updated_at'];
    
    public function warehouse() {
        return $this->belongsTo(Warehouse::class);
    }
}
