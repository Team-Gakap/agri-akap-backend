<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class SubsidyBeneficiary extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'tbl_subsidy_beneficiaries';

    protected $fillable = [
        'program_id',
        'batch_id',
        'farmer_id',
        'farmer_rsbsa_no',
        'is_walkin',
        'calculated_allocation',
        'calculated_allocation_secondary',
        'source_farm_area',
        'source_commodity',
        'source_farmer_barangay',
        'source_farmer_municipality',
        'source_farm_barangay',
        'source_farm_municipality',
        'status',
        'priority_tier',
        'selection_mode',
        'claimed_at',
        'claimed_by',
        'photo_proof_path',
        'override_by_admin_id',
        'override_timestamp',
        'override_justification',
        'override_reason_code',
    ];

    protected $casts = [
        'calculated_allocation' => 'decimal:2',
        'calculated_allocation_secondary' => 'decimal:2',
        'source_farm_area' => 'decimal:4',
        'priority_tier' => 'integer',
        'is_walkin' => 'boolean',
        'claimed_at' => 'datetime',
        'override_timestamp' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SubsidyProgram::class, 'program_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }

    public function farmerByRsbsa(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_rsbsa_no', 'rsbsa_no');
    }

    public function overrideAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by_admin_id');
    }

    public static function applyNotDeleted($query, string $column = 'tbl_subsidy_beneficiaries.deleted_at')
    {
        if (Schema::hasColumn('tbl_subsidy_beneficiaries', 'deleted_at')) {
            $query->whereNull($column);
        }

        return $query;
    }
}
