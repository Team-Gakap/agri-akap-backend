<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DamageAssessment extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'id', // allow client-generated UUID for offline-first idempotency
        'farm_plot_id',
        'farmer_id',
        'technician_id',
        'calamity_name',
        'calamity_type',
        'crop_stage',
        'variety',
        'area_destroyed_ha',
        'area_planted_ha',
        'date_of_calamity',
        'damage_percentage',
        'estimated_value_lost',
        'photo_evidence_path',
        'latitude',
        'longitude',
        'status',
        'verified_by',
        'verified_at',
        'approved_by',
        'approved_at',
        'remarks',
        'device_id',
    ];

    protected $casts = [
        'date_of_calamity' => 'date',
        'damage_percentage' => 'decimal:2',
        'estimated_value_lost' => 'decimal:2',
        'area_destroyed_ha' => 'decimal:4',
        'area_planted_ha' => 'decimal:4',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected $appends = ['photo_url'];

    /**
     * Public URL for the stored evidence photo (public disk).
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (empty($this->photo_evidence_path)) {
            return null;
        }

        return public_storage_url($this->photo_evidence_path);
    }

    public function farmPlot(): BelongsTo
    {
        return $this->belongsTo(FarmPlot::class, 'farm_plot_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function noticeFiler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pcic_notice_filed_by');
    }

}
