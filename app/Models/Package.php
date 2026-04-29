<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $guarded = [];
    protected $hidden = ['created_at', 'updated_at'];
    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }
}
