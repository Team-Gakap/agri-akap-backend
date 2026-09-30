<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualFarmerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'surname' => 'required|string|max:100',
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'sex' => 'required|in:Male,Female',
            'birthdate' => 'required|date',
            'mobile_number' => 'required|string|max:15',
            'commodity' => 'required|string|max:64',
            'hectares' => 'required|numeric|min:0.01|max:9999',
            'barangay_name' => 'nullable|string|max:255',
            'declared_sitio' => 'nullable|string|max:255',
            'enlistment_remarks' => 'nullable|string|max:500',
        ];
    }
};
