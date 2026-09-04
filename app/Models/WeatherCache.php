<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeatherCache extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'tbl_weather_cache';

    protected $fillable = [
        'barangay_name',
        'forecast_date',
        'temperature_min',
        'temperature_max',
        'precipitation_probability',
        'precipitation_sum',
        'soil_moisture',
        'evapotranspiration',
        'soil_moisture_28cm',
        'wind_speed_10m',
        'weather_code',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'temperature_min' => 'decimal:2',
        'temperature_max' => 'decimal:2',
        'precipitation_probability' => 'integer',
        'precipitation_sum' => 'decimal:2',
        'soil_moisture' => 'decimal:3',
        'evapotranspiration' => 'decimal:3',
        'soil_moisture_28cm' => 'decimal:3',
        'wind_speed_10m' => 'decimal:2',
        'weather_code' => 'integer',
    ];
}
