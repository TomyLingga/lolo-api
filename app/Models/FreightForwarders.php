<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FreightForwarders extends Model
{
    protected $guarded = [];

    public function registration() {
        return $this->hasMany(Registration::class);
    }
}
