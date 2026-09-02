<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HarvestLog extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'harvest_logs';

    protected $fillable = [
        'id',
        'client_id',
        'farmer_id',
        'farm_plot_id',
        'technician_id',
        'crop_type',
        'variety',
        'area_harvested',
        'total_yield',
        'yield_unit',
        'date_harvested',
        'farm_location',
    ];

    protected $casts = [
        'date_harvested' => 'date',
        'area_harvested' => 'decimal:4',
        'total_yield' => 'decimal:2',
    ];

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function farmPlot(): BelongsTo
    {
        return $this->belongsTo(FarmPlot::class, 'farm_plot_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
