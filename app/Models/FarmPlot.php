<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FarmPlot extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'farmer_id', 'parcel_name',
        'location_brgy', 'location_city', 'location_province',
        'latitude', 'longitude', 'georef_id', 'total_parcel_area_ha', 'is_ancestral_domain',
        'is_agrarian_reform_beneficiary', 'ownership_type', 'land_owner_first_name',
        'land_owner_surname', 'land_owner_ext_name', 'landowner_name', 'land_owner_rsbsa_no',
        'proof_of_ownership_document',
        'commodity', 'planting_start_month', 'planting_end_month',
        'size_ha', 'no_of_heads_or_trees', 'farm_type', 'is_organic',
        'cropping_schedule', 'rotational_tiller_full_name', 'remarks',
        'boundary_points', 'non_productive_area_sqm', 'has_discrepancy', 'georef_sms_sent_at',
        'geotag_status', 'geotag_assigned_user_id', 'geotag_assigned_name',
        'geotag_priority', 'geotag_notes', 'geotag_deadline',
    ];

    protected $casts = [
        'is_ancestral_domain' => 'boolean',
        'is_agrarian_reform_beneficiary' => 'boolean',
        'is_organic' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'total_parcel_area_ha' => 'decimal:4',
        'size_ha' => 'decimal:4',
        'boundary_points' => 'array',
        'non_productive_area_sqm' => 'decimal:2',
        'has_discrepancy' => 'boolean',
        'georef_sms_sent_at' => 'datetime',
        'geotag_deadline' => 'date',
    ];

    /** MySQL POINT `coordinates` is binary and cannot be JSON-encoded. */
    protected $hidden = ['coordinates'];

    protected static function booted(): void
    {
        static::saving(function (FarmPlot $plot) {
            if ($plot->hasGeoTagEvidence()) {
                $plot->geotag_status = 'mapped';
            } elseif (! in_array($plot->geotag_status, ['pending_field', 'mapped'], true)) {
                $plot->geotag_status = 'unmapped';
            }
        });
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'geotag_assigned_user_id');
    }

    /** True when the plot has a GEOREF id, boundary polygon, or a real GPS fix. */
    public function hasGeoTagEvidence(): bool
    {
        if (filled($this->georef_id) || ! empty($this->boundary_points)) {
            return true;
        }

        if ($this->latitude === null || $this->longitude === null) {
            return false;
        }

        return abs((float) $this->latitude) > 0.0001 || abs((float) $this->longitude) > 0.0001;
    }

    /** Parcels that should appear as Mapped in the registry. */
    public function scopeMapped($query)
    {
        return $query->where(function ($inner) {
            $inner->where('geotag_status', 'mapped')
                ->orWhere(function ($g) {
                    $g->whereNotNull('georef_id')->where('georef_id', '!=', '');
                })
                ->orWhereNotNull('boundary_points')
                ->orWhere(function ($g) {
                    $g->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->where(function ($c) {
                            $c->whereRaw('ABS(latitude) > 0.0001')
                                ->orWhereRaw('ABS(longitude) > 0.0001');
                        });
                });
        });
    }

    /** Dispatched for field walk and still missing GPS / GEOREF / boundary. */
    public function scopePendingFieldGeotag($query)
    {
        return $query->where('geotag_status', 'pending_field')
            ->where(function ($g) {
                $g->whereNull('georef_id')->orWhere('georef_id', '');
            })
            ->whereNull('boundary_points')
            ->where(function ($g) {
                $g->whereNull('latitude')
                    ->orWhereNull('longitude')
                    ->orWhere(function ($c) {
                        $c->whereRaw('ABS(COALESCE(latitude, 0)) <= 0.0001')
                            ->whereRaw('ABS(COALESCE(longitude, 0)) <= 0.0001');
                    });
            });
    }
}
