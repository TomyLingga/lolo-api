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
        return $this->belongsTo(User::class, 'moved_by');
    }

    public function calculateCost($days, $pricePerDay = null)
    {
        $freeTimeDays = $this->registration->package->free_time_days ?? 0;
        
        $previousDays = $this->registration->storageRecords()
            ->where('id', '!=', $this->id)
            ->whereNotNull('end_date')
            ->where(function($q) {
                $q->where('start_date', '<', $this->start_date)
                  ->orWhere(function($q2) {
                      $q2->where('start_date', '=', $this->start_date)
                         ->where('id', '<', $this->id);
                  });
            })
            ->sum('total_storage_days');
            
        $freeTimeAvailable = max(0, $freeTimeDays - $previousDays);
        $freeTimeUsed = min($days, $freeTimeAvailable);
        $taxableDays = max(0, $days - $freeTimeUsed);
        
        $rate = $pricePerDay ?? $this->storage_price_per_day;
        return $taxableDays * $rate;
    }
}
