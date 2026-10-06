<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubsidyProgramVariety extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'tbl_subsidy_program_varieties';

    protected $fillable = [
        'program_id',
        'variety_name',
        'unit',
        'bags_per_hectare',
        'total_quantity',
        'remaining_quantity',
        'reorder_level',
        'sort_order',
    ];

    protected $casts = [
        'bags_per_hectare'   => 'decimal:4',
        'total_quantity'     => 'decimal:2',
        'remaining_quantity' => 'decimal:2',
        'reorder_level'      => 'decimal:2',
        'sort_order'         => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SubsidyProgram::class, 'program_id');
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(SubsidyBeneficiary::class, 'variety_id');
    }

    /**
     * The unit to display in UIs: falls back to the parent program's unit when
     * this variety did not override it.
     */
    public function effectiveUnit(): string
    {
        return $this->unit ?? $this->program->unit_of_measurement ?? 'Bags';
    }
}
