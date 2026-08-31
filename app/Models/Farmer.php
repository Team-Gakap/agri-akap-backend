<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Farmer extends Model
{
    /** @use HasFactory<\Database\Factories\FarmerFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'rsbsa_no', 'transaction_code', 'photo_path', 'qr_code_hash',
        'is_probable_duplicate',
        'verification_status', 'rts_reason', 'verified_at',
        'surname', 'first_name', 'middle_name', 'ext_name',
        'no_middle_name', 'no_ext_name', 'sex',

        // Permanent Address
        'permanent_house_no', 'permanent_street', 'permanent_brgy',
        'permanent_city', 'permanent_province', 'permanent_region',

        // Provincial Address
        'provincial_house_no', 'provincial_street', 'provincial_brgy',
        'provincial_city', 'provincial_province', 'provincial_region',

        // Birth & Contact
        'birthdate', 'place_of_birth_city', 'place_of_birth_province',
        'mobile_number', 'is_mobile_owner',
        'mobile_owner_first_name', 'mobile_owner_middle_name',
        'mobile_owner_surname', 'mobile_owner_ext_name',

        // Mother's Maiden Name
        'mothers_maiden_first_name', 'mothers_maiden_middle_name',
        'mothers_maiden_surname', 'mothers_maiden_ext_name',

        // Demographics
        'civil_status', 'spouse_first_name', 'spouse_middle_name',
        'spouse_surname', 'spouse_ext_name',
        'highest_education', 'religion', 'id_type', 'id_number',

        // Vulnerability & Affiliations
        'is_icc_ip', 'icc_ip_name', 'is_pwd', 'is_4ps_beneficiary',
        'association_1', 'association_2', 'association_3', 'livelihood_type',
        'livelihood_detail',
        'total_farm_area_ha',
    ];

    protected $casts = [
        'no_middle_name' => 'boolean',
        'no_ext_name' => 'boolean',
        'is_mobile_owner' => 'boolean',
        'is_icc_ip' => 'boolean',
        'is_pwd' => 'boolean',
        'is_4ps_beneficiary' => 'boolean',
        'is_probable_duplicate' => 'boolean',
        'birthdate' => 'date',
        'verified_at' => 'datetime',
        'total_farm_area_ha' => 'decimal:4',
    ];

    protected $appends = ['is_rffa_eligible'];

    /**
     * Get all subsidy claims for this farmer.
     */
    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    /**
     * Get the farm plots associated with the farmer.
     */
    public function farmPlots(): HasMany
    {
        return $this->hasMany(FarmPlot::class, 'farmer_id');
    }

    /**
     * Local Scope: Filter farmers by common search terms.
     */
    public function scopeSearch($query, string $term)
    {
        // Wrap the search term in SQL wildcards for a partial match
        $term = "%{$term}%";

        // We wrap these OR conditions in a closure to ensure they are grouped in SQL
        // like: WHERE (...) AND deleted_at IS NULL
        $query->where(function ($q) use ($term) {
            $q->where('rsbsa_no', 'like', $term)
              ->orWhere('transaction_code', 'like', $term)
              ->orWhere('first_name', 'like', $term)
              ->orWhere('surname', 'like', $term)
              ->orWhere('middle_name', 'like', $term)
              ->orWhere('permanent_brgy', 'like', $term) // Helpful for geographic filtering
              ->orWhere('permanent_city', 'like', $term);
        });
    }

    /**
     * FFRS 2.0 RFFA Eligibility — computed accessor.
     *
     * A farmer is eligible for the Rice Farmers Financial Assistance (RFFA)
     * program when ALL of the following criteria are met:
     *   1. They have at least one active farm plot where commodity = 'Rice'
     *   2. The sum of ALL their farm plot areas is ≤ 2.0 hectares
     *
     * @return bool
     */
    public function getIsRffaEligibleAttribute(): bool
    {
        // Eager-load farm plots if not already loaded to avoid N+1.
        if (! $this->relationLoaded('farmPlots')) {
            $this->load('farmPlots');
        }

        $plots = $this->farmPlots;

        // No plots → not eligible.
        if ($plots->isEmpty()) {
            return false;
        }

        // Check criterion 1: at least one Rice plot.
        $hasRice = $plots->contains(function ($plot) {
            return ! $plot->deleted_at &&
                   strcasecmp(trim((string) $plot->commodity), 'Rice') === 0;
        });

        if (! $hasRice) {
            return false;
        }

        // Check criterion 2: total area ≤ 2.0 ha (across ALL plots, not just Rice).
        $totalHa = $plots->where('deleted_at', null)->sum('size_ha');

        return $totalHa > 0 && $totalHa <= 2.0;
    }
}
