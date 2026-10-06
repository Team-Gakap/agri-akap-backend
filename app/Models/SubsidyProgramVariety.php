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
        'target_fca',
        'target_barangays',
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
        'target_barangays'   => 'array',
    ];

    /**
     * True when this variety is pre-assigned to the farmer's farm barangay or FCA.
     */
    public function isRecommendedFor(?string $farmBrgy, ?string $fcaName = null): bool
    {
        $targets = is_array($this->target_barangays) ? $this->target_barangays : [];
        if ($farmBrgy && $targets) {
            foreach ($targets as $brgy) {
                if (strcasecmp(trim((string) $brgy), trim($farmBrgy)) === 0) {
                    return true;
                }
            }
        }

        if ($fcaName && $this->target_fca) {
            return strcasecmp(trim($this->target_fca), trim($fcaName)) === 0;
        }

        return false;
    }

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
