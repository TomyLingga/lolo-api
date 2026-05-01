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
        return $this->belongsTo(FreightForwarders::class, 'freight_forwarder_id');
    }

    public function createdBy() {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoiceRegistrations()
    {
        return $this->hasMany(InvoiceRegistration::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function shipperTenant() {
        return $this->belongsTo(FreightForwarders::class, 'shipper_tenant_id');
    }
}
