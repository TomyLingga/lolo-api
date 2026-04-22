<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoloRecord extends Model
{
    protected $guarded = [];

    public function registration() {
        return $this->belongsTo(Registration::class);
    }

    public function invoiceRegistrations()
    {
        return $this->hasMany(InvoiceRegistration::class, 'lolo_record_id');
    }

    public function tax() {
        return $this->belongsTo(Tax::class);
    }

    public function createdBy() {
        return $this->belongsTo(User::class, 'created_by');
    }
}
