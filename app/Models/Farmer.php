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
        'rsbsa_no', 'registration_type', 'is_temporary', 'enlisted_by_user_id', 'enlistment_remarks', 'declared_sitio',
        'transaction_code', 'photo_path', 'qr_code_hash',
        'is_probable_duplicate',
        'verification_status', 'rts_reason', 'verified_at',
        'surname', 'first_name', 'middle_name', 'ext_name',
        'no_middle_name', 'no_ext_name', 'sex',

        // Permanent Address
        'permanent_house_no', 'permanent_street', 'permanent_brgy',
        'permanent_city', 'permanent_province', 'permanent_region',

        // Farm location (distribution / drop-off barangay)
        'farm_brgy', 'farm_city', 'farm_psgc', 'farmer_psgc',

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
        'highest_education', 'religion', 'id_type', 'id_type_other', 'id_number',

        // Vulnerability & Affiliations
        'is_icc_ip', 'icc_ip_name', 'is_pwd', 'is_4ps_beneficiary',
        'association_1', 'association_2', 'association_3', 'associations', 'livelihood_type',
        'livelihood_detail', 'other_livelihood_type', 'other_livelihood_detail',
        'total_farm_area_ha',
        'subsidy_exclusion_reason',
        'masterlist_remarks',
    ];

    protected $casts = [
        'no_middle_name' => 'boolean',
        'no_ext_name' => 'boolean',
        'is_mobile_owner' => 'boolean',
        'is_icc_ip' => 'boolean',
        'is_pwd' => 'boolean',
        'is_4ps_beneficiary' => 'boolean',
        'is_probable_duplicate' => 'boolean',
        'is_temporary' => 'boolean',
        'birthdate' => 'date',
        'verified_at' => 'datetime',
        'total_farm_area_ha' => 'decimal:4',
        'associations' => 'array',
    ];

    protected $appends = ['is_rffa_eligible'];

    /**
     * Slim plot columns for list JSON. Hidden so registry responses do not
     * embed parcels; the accessor still reads the loaded relation.
     *
     * @var list<string>
     */
    protected $hidden = ['rffaPlots'];

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
     * Plot columns required for RFFA eligibility, without polygon/POINT payloads.
     */
    public function rffaPlots(): HasMany
    {
        return $this->hasMany(FarmPlot::class, 'farmer_id')
            ->select([
                'farm_plots.id',
                'farm_plots.farmer_id',
                'farm_plots.commodity',
                'farm_plots.size_ha',
                'farm_plots.deleted_at',
            ]);
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
              ->orWhere('permanent_brgy', 'like', $term)
              ->orWhere('farm_brgy', 'like', $term)
              ->orWhere('permanent_city', 'like', $term)
              ->orWhere('farm_city', 'like', $term)
              ->orWhereRaw(
                  "CONCAT(surname, ', ', first_name, ' ', COALESCE(middle_name, '')) LIKE ?",
                  [$term]
              )
              ->orWhereRaw("CONCAT(first_name, ' ', surname) LIKE ?", [$term])
              ->orWhereRaw("CONCAT(surname, ' ', first_name) LIKE ?", [$term]);
        });
    }

    /**
     * FFRS 2.0 RFFA Eligibility — computed accessor.
     *
     * A farmer is eligible for the Rice Farmers Financial Assistance (RFFA)
     * program when ALL of the following criteria are met:
     *   1. They have at least one active farm plot whose commodity contains "rice"
     *      (Rice, Rice/Palay). HVCC labels are excluded.
     *   2. The sum of ALL their farm plot areas is ≤ 2.0 hectares
     *
     * @return bool
     */
    public function getIsRffaEligibleAttribute(): bool
    {
        $plots = $this->plotsForRffa();

        // No plots → not eligible.
        if ($plots->isEmpty()) {
            return false;
        }

        // Rice, Rice/Palay, and RICE all qualify. HVCC names that merely contain "rice" do not.
        $hasRice = $plots->contains(function ($plot) {
            if ($plot->deleted_at) {
                return false;
            }
            $commodity = strtolower(trim((string) $plot->commodity));

            return str_contains($commodity, 'rice') && ! str_contains($commodity, 'hvcc');
        });

        if (! $hasRice) {
            return false;
        }

        // Check criterion 2: total area ≤ 2.0 ha (across ALL plots, not just Rice).
        $totalHa = $plots->where('deleted_at', null)->sum('size_ha');

        return $totalHa > 0 && $totalHa <= 2.0;
    }

    /**
     * Prefer an already-loaded plot relation. Otherwise one slim query, cached
     * on rffaPlots so a list page does not pull POINT columns per farmer.
     */
    private function plotsForRffa(): \Illuminate\Support\Collection
    {
        if ($this->relationLoaded('rffaPlots')) {
            return $this->rffaPlots;
        }

        if ($this->relationLoaded('farmPlots')) {
            return $this->farmPlots;
        }

        $plots = $this->rffaPlots()->get();
        $this->setRelation('rffaPlots', $plots);

        return $plots;
    }
}
