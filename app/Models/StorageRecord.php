<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StorageRecord extends Model
{
    protected $guarded = [];

    public function registration() {
        return $this->belongsTo(Registration::class);
    }

    public function yard() {
        return $this->belongsTo(Yard::class);
    }

    public function block() {
        return $this->belongsTo(Block::class);
    }

    public function cargoStatus() {
        return $this->belongsTo(CargoStatus::class);
    }

    public function movedBy() {
        return $this->belongsTo(User::class, 'created_by');
    }
}
