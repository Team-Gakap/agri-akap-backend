<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubsidyProgram extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'tbl_subsidy_programs';

    protected $fillable = [
        'program_name',
        'target_crop',
        'max_hectares_limit',
        'min_hectares_limit',
        'items_per_hectare',
        'status',
        'unit_of_measurement',
        'total_quantity',
        'remaining_quantity',
        'reorder_level',
    ];

    protected $casts = [
        'max_hectares_limit' => 'decimal:4',
        'min_hectares_limit' => 'decimal:4',
        'items_per_hectare' => 'integer',
        'total_quantity' => 'integer',
        'remaining_quantity' => 'integer',
        'reorder_level' => 'integer',
    ];

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(SubsidyBeneficiary::class, 'program_id');
    }
}
