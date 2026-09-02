<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PestMonitoring extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'pest_monitoring';

    protected $fillable = [
        'id',
        'client_id',
        'farmer_id',
        'farm_plot_id',
        'technician_id',
        'crop',
        'crop_stage',
        'variety',
        'area_planted',
        'days_after_planting',
        'area_damage_pct',
        'farm_location',
        'date_of_inspection',
        'pest_name',
        'incidence',
        'severity',
        'advisory',
        'is_outbreak',
        'photo_path',
        'latitude',
        'longitude',
        'report_ref',
        'item_distributed',
        'quantity',
        'device_id',
    ];

    protected $casts = [
        'is_outbreak' => 'boolean',
        'incidence' => 'integer',
        'area_planted' => 'decimal:4',
        'area_damage_pct' => 'decimal:2',
        'date_of_inspection' => 'date',
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
