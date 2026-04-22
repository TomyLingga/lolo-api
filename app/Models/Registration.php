<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    protected $guarded = [];

    public function size() {
        return $this->belongsTo(ContainerSize::class, 'container_size_id');
    }

    public function type() {
        return $this->belongsTo(ContainerType::class, 'container_type_id');
    }

    public function cargoStatus() {
        return $this->belongsTo(CargoStatus::class);
    }

    public function storageRecords() {
        return $this->hasMany(StorageRecord::class);
    }

    public function loloRecords() {
        return $this->hasMany(LoloRecord::class);
    }

    public function registrationRemarks() {
        return $this->hasMany(RegistrationRemark::class);
    }

    public function freightForwarders() {
        return $this->hasOne(FreightForwarders::class);
    }

    public function createdBy() {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoiceRegistrations()
    {
        return $this->hasMany(InvoiceRegistration::class);
    }
}
