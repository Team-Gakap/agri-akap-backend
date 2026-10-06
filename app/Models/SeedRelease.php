<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeedRelease extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'tbl_seed_releases';

    protected $fillable = [
        'farmer_id',
        'variety_id',
        'farmer_rsbsa_no',
        'farm_barangay',
        'farm_area_ha',
        'quantity',
        'unit',
        'released_by',
        'claimed_at',
        'device_id',
        'geo_tag_lat',
        'geo_tag_long',
        'override_reason',
        'override_reason_code',
        'override_justification',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'farm_area_ha' => 'decimal:4',
        'quantity' => 'decimal:2',
        'claimed_at' => 'datetime',
        'voided_at' => 'datetime',
        'geo_tag_lat' => 'decimal:7',
        'geo_tag_long' => 'decimal:7',
    ];

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }

    public function variety(): BelongsTo
    {
        return $this->belongsTo(SeedVariety::class, 'variety_id');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
