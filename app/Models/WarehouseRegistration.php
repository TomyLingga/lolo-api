<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class WarehouseRegistration extends Model
{
    protected $fillable = [
        'freight_forwarder_id',
        'chamber_id',
        'tariff_per_m2',
        'area_m2',
        'rent_start',
        'rent_end',
        'record_status',
        'invoiced',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'tariff_per_m2' => 'decimal:2',
        'area_m2'       => 'decimal:2',
        'rent_start'    => 'date',
        'rent_end'      => 'date',
        'invoiced'      => 'boolean',
        'is_active'     => 'boolean',
    ];

    // ─── Appends ─────────────────────────────────────────────────────────────

    protected $appends = [
        'subtotal',
        'total_rent_days',
        'total_rent_cost',
    ];

    // ─── Accessors ───────────────────────────────────────────────────────────

    /**
     * Subtotal = area × tarif per m²
     */
    public function getSubtotalAttribute(): float
    {
        return round((float) $this->area_m2 * (float) $this->tariff_per_m2, 2);
    }

    /**
     * Jumlah hari sewa (inklusif kedua ujung)
     * Null jika rent_end belum diset
     */
    public function getTotalRentDaysAttribute(): ?int
    {
        if (! $this->rent_start || ! $this->rent_end) {
            return null;
        }

        return Carbon::parse($this->rent_start)
                     ->diffInDays(Carbon::parse($this->rent_end)) + 1;
    }

    /**
     * Total biaya sewa = subtotal × jumlah bulan
     * Dihitung dari total hari / 30 (dibulatkan ke atas per bulan)
     * Minimal 1 bulan
     */
    public function getTotalRentCostAttribute(): float
    {
        $days = $this->total_rent_days;

        if (! $days) {
            return 0.0;
        }

        // Minimal 1 bulan, dibulatkan ke atas
        $months = (int) ceil($days / 30);
        $months = max(1, $months);

        return round($this->subtotal * $months, 2);
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function freightForwarder()
    {
        return $this->belongsTo(FreightForwarders::class, 'freight_forwarder_id');
    }

    public function chamber()
    {
        return $this->belongsTo(WarehouseChamber::class, 'chamber_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function remarks()
    {
        return $this->hasMany(WarehouseRegistrationRemark::class, 'warehouse_registration_id')
                    ->orderBy('created_at', 'asc');
    }

    public function beritaAcaraRegistrations()
    {
        return $this->hasMany(WarehouseBaRegistration::class, 'warehouse_registration_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('record_status', 'ACTIVE')->where('is_active', true);
    }

    public function scopeClosed($query)
    {
        return $query->where('record_status', 'CLOSED');
    }

    public function scopeNotInvoiced($query)
    {
        return $query->where('record_status', 'CLOSED')
                     ->where('invoiced', false)
                     ->where('is_active', true);
    }

    // ─── Static Helpers ──────────────────────────────────────────────────────

    /**
     * Cek overlap periode sewa pada chamber yang sama.
     * Dipakai di store() dan update() controller.
     */
    public static function hasOverlap(
        int $chamberId,
        string $rentStart,
        string $rentEnd,
        ?int $excludeId = null
    ): bool {
        return static::where('chamber_id', $chamberId)
            ->where('is_active', true)
            ->where('record_status', 'ACTIVE')
            ->where('rent_start', '<=', $rentEnd)
            ->where('rent_end', '>=', $rentStart)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    /**
     * Hitung jumlah bulan dari rentang tanggal.
     * Minimal 1, dibulatkan ke atas.
     */
    public static function calculateMonths(string $rentStart, string $rentEnd): int
    {
        $days = Carbon::parse($rentStart)->diffInDays(Carbon::parse($rentEnd)) + 1;
        return max(1, (int) ceil($days / 30));
    }
}
