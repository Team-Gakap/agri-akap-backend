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
        'seed_class',
        'item_type',
        'max_hectares_limit',
        'min_hectares_limit',
        'items_per_hectare',
        'secondary_items_per_hectare',
        'status',
        'unit_of_measurement',
        'secondary_unit',
        'total_quantity',
        'remaining_quantity',
        'reorder_level',
        'secondary_total_quantity',
        'secondary_remaining_quantity',
        'secondary_reorder_level',
    ];

    protected $casts = [
        'max_hectares_limit' => 'decimal:4',
        'min_hectares_limit' => 'decimal:4',
        'items_per_hectare' => 'decimal:2',
        'secondary_items_per_hectare' => 'decimal:2',
        'total_quantity' => 'decimal:2',
        'remaining_quantity' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'secondary_total_quantity' => 'decimal:2',
        'secondary_remaining_quantity' => 'decimal:2',
        'secondary_reorder_level' => 'decimal:2',
    ];

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(SubsidyBeneficiary::class, 'program_id');
    }
}
