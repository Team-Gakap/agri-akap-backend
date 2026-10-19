<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlantingLog extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'planting_logs';

    protected $fillable = [
        'id',
        'client_id',
        'farmer_id',
        'farm_plot_id',
        'technician_id',
        'crop_type',
        'variety',
        'area_planted',
        'date_planted',
        'status',
        'water_source',
        'farm_location',
        'remarks',
        'latitude',
        'longitude',
        'device_id',
    ];

    protected $casts = [
        'date_planted' => 'date',
        'area_planted' => 'decimal:4',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
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
