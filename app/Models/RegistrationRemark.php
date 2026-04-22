<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistrationRemark extends Model
{
    protected $guarded = [];

    public function registration() {
        return $this->belongsTo(Registration::class);
    }

    public function createdBy() {
        return $this->belongsTo(User::class, 'created_by');
    }
}
