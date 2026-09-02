<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubsidyBeneficiary extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'tbl_subsidy_beneficiaries';

    protected $fillable = [
        'program_id',
        'farmer_rsbsa_no',
        'calculated_allocation',
        'calculated_allocation_secondary',
        'status',
        'claimed_at',
        'claimed_by',
        'photo_proof_path',
    ];

    protected $casts = [
        'calculated_allocation' => 'decimal:2',
        'calculated_allocation_secondary' => 'decimal:2',
        'claimed_at' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SubsidyProgram::class, 'program_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_rsbsa_no', 'rsbsa_no');
    }
}
