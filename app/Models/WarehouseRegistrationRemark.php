<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseRegistrationRemark extends Model
{
    protected $fillable = ['warehouse_registration_id', 'remark', 'created_by'];

    public function registration()
    {
        return $this->belongsTo(WarehouseRegistration::class, 'warehouse_registration_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
