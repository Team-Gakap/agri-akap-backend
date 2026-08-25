<?php

namespace App\Http\Requests;

use App\Support\OfficialBarangays;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFarmerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'rsbsa_no' => [
                'nullable', 'string', 'max:255',
                Rule::unique('farmers', 'rsbsa_no')->ignore($id),
            ],
            'transaction_code' => [
                'required', 'string', 'max:255',
                Rule::unique('farmers', 'transaction_code')->ignore($id),
            ],
            'photo_base64' => 'nullable|string',

            'surname' => 'required|string|max:100',
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'ext_name' => 'nullable|string|max:10',
            'no_middle_name' => 'boolean',
            'no_ext_name' => 'boolean',
            'sex' => 'required|in:Male,Female',
            'birthdate' => 'required|date',
            'place_of_birth_city' => 'nullable|string|max:100',
            'place_of_birth_province' => 'nullable|string|max:100',

            'mobile_number' => 'required|string|max:15',
            'is_mobile_owner' => 'boolean',
            'mobile_owner_first_name' => 'nullable|string|max:100',
            'mobile_owner_middle_name' => 'nullable|string|max:100',
            'mobile_owner_surname' => 'nullable|string|max:100',
            'mobile_owner_ext_name' => 'nullable|string|max:10',

            'mothers_maiden_first_name' => 'required|string|max:100',
            'mothers_maiden_middle_name' => 'nullable|string|max:100',
            'mothers_maiden_surname' => 'required|string|max:100',
            'mothers_maiden_ext_name' => 'nullable|string|max:10',

            'civil_status' => 'required|in:Single,Married,Widow/er,Legally Separated',
            'spouse_first_name' => 'nullable|string|max:100',
            'spouse_middle_name' => 'nullable|string|max:100',
            'spouse_surname' => 'nullable|string|max:100',
            'spouse_ext_name' => 'nullable|string|max:10',
            'highest_education' => 'required|in:Pre-school,Elementary,High School non K-12,Junior High School K-12,Senior High School K-12,College,Vocational,Post-graduate,None',
            'religion' => 'nullable|string|max:100',
            'id_type' => 'nullable|string|max:100',
            'id_number' => 'nullable|string|max:100',

            'is_icc_ip' => 'boolean',
            'icc_ip_name' => 'nullable|string|max:255',
            'is_pwd' => 'boolean',
            'is_4ps_beneficiary' => 'boolean',
            'association_1' => 'nullable|string|max:255',
            'association_2' => 'nullable|string|max:255',
            'association_3' => 'nullable|string|max:255',

            'permanent_house_no' => 'nullable|string|max:50',
            'permanent_street' => 'nullable|string|max:100',
            'permanent_brgy' => ['required', 'string', 'max:100', Rule::in(OfficialBarangays::names())],
            'permanent_city' => 'required|string|max:100',
            'permanent_province' => 'required|string|max:100',
            'permanent_region' => 'required|string|max:100',

            'provincial_house_no' => 'nullable|string|max:50',
            'provincial_street' => 'nullable|string|max:100',
            'provincial_brgy' => 'nullable|string|max:100',
            'provincial_city' => 'nullable|string|max:100',
            'provincial_province' => 'nullable|string|max:100',
            'provincial_region' => 'nullable|string|max:100',

            'livelihood_type' => 'required|in:Farmer,Farm Worker,Fisher,Agri-Youth',
            'livelihood_detail' => 'nullable|string|max:100',

            'plots' => 'nullable|array|min:1',
            'plots.*.id' => 'nullable|uuid',
            'plots.*.parcel_name' => 'nullable|string|max:100',
            'plots.*.location_brgy' => ['required_with:plots', 'string', 'max:100', Rule::in(OfficialBarangays::names())],
            'plots.*.location_city' => 'required_with:plots|string|max:100',
            'plots.*.location_province' => 'required_with:plots|string|max:100',
            'plots.*.total_parcel_area_ha' => 'required_with:plots|numeric|min:0.01',
            'plots.*.is_ancestral_domain' => 'boolean',
            'plots.*.is_agrarian_reform_beneficiary' => 'boolean',
            'plots.*.ownership_type' => 'required_with:plots|in:Registered Owner,Tenant,Lessee,Others',
            'plots.*.land_owner_first_name' => 'required_if:plots.*.ownership_type,Tenant,Lessee|nullable|string|max:100',
            'plots.*.land_owner_surname' => 'required_if:plots.*.ownership_type,Tenant,Lessee|nullable|string|max:100',
            'plots.*.land_owner_ext_name' => 'nullable|string|max:10',
            'plots.*.land_owner_rsbsa_no' => 'required_if:plots.*.ownership_type,Tenant,Lessee|nullable|string|max:100',
            'plots.*.proof_of_ownership_document' => 'required_with:plots|string|max:100',
            'plots.*.commodity' => 'required_with:plots|string|max:100',
            'plots.*.planting_start_month' => 'nullable|string|max:20',
            'plots.*.planting_end_month' => 'nullable|string|max:20',
            'plots.*.size_ha' => 'required_with:plots|numeric|min:0.01',
            'plots.*.no_of_heads_or_trees' => 'nullable|integer|min:0',
            'plots.*.farm_type' => 'required_with:plots|in:Irrigated,Rainfed Upland,Rainfed Lowland,Urban/Peri-Urban',
            'plots.*.is_organic' => 'boolean',
            'plots.*.cropping_schedule' => 'nullable|string|max:100',
            'plots.*.rotational_tiller_full_name' => 'nullable|string|max:255',
            'plots.*.remarks' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'permanent_brgy.in' => 'Select an official Echague barangay.',
            'plots.*.location_brgy.in' => 'Each plot must use an official Echague barangay.',
            'plots.*.commodity.in' => 'Select a valid commodity (Rice, Corn, High-Value Crops).',
            'plots.*.land_owner_first_name.required_if' => 'Landowner first name is required for tenant- or lessee-tilled parcels.',
            'plots.*.land_owner_surname.required_if' => 'Landowner surname is required for tenant- or lessee-tilled parcels.',
            'plots.*.land_owner_rsbsa_no.required_if' => 'Landowner RSBSA number is required for tenant- or lessee-tilled parcels.',
        ];
    }
}
