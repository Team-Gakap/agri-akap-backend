<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeatherCurrent extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'tbl_weather_current';

    protected $fillable = [
        'barangay_name',
        'observed_at',
        'temperature',
        'precipitation',
        'rain',
        'precipitation_probability',
        'wind_speed',
        'weather_code',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
        'temperature' => 'decimal:2',
        'precipitation' => 'decimal:2',
        'rain' => 'decimal:2',
        'precipitation_probability' => 'integer',
        'wind_speed' => 'decimal:2',
        'weather_code' => 'integer',
    ];
}
