<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookWeatherPost extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'tbl_facebook_weather_posts';

    protected $fillable = [
        'forecast_date',
        'window',
        'caption',
        'image_path',
        'facebook_post_id',
        'posted_by',
    ];

    protected $casts = [
        'forecast_date' => 'date',
    ];

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
