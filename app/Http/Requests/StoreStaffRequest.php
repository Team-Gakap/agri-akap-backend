<?php

namespace App\Http\Requests;

use App\Support\OfficialBarangays;
use App\Support\StaffAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->role === 'admin');
    }

    public function rules(): array
    {
        $roles = StaffAccess::creatableRoles($this->user());

        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => ['required', 'string', Rule::in($roles)],
            'assigned_barangay' => [
                Rule::requiredIf(fn () => $this->input('role') === 'barangay_official'),
                'nullable',
                'string',
                'max:255',
                Rule::in(OfficialBarangays::names()),
            ],
            'is_active' => 'sometimes|boolean',
            'enforce_mfa' => 'sometimes|boolean',
        ];
    }
}
