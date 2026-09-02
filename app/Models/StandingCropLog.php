<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandingCropLog extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'standing_crop_logs';

    protected $fillable = [
        'id',
        'client_id',
        'farmer_id',
        'farm_plot_id',
        'technician_id',
        'crop_type',
        'variety',
        'area_ha',
        'growth_stage',
        'est_harvest_date',
        'farm_location',
    ];

    protected $casts = [
        'est_harvest_date' => 'date',
        'area_ha' => 'decimal:4',
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
