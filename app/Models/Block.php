<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    protected $guarded = [];

    public function yard() {
        return $this->belongsTo(Yard::class);
    }
}
