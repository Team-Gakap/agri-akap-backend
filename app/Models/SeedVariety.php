<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeedVariety extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'tbl_seed_varieties';

    protected $fillable = [
        'variety_name',
        'unit',
        'bags_per_hectare',
        'total_quantity',
        'remaining_quantity',
        'reorder_level',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'bags_per_hectare' => 'decimal:4',
        'total_quantity' => 'decimal:2',
        'remaining_quantity' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function releases(): HasMany
    {
        return $this->hasMany(SeedRelease::class, 'variety_id');
    }
}
