<?php

namespace App\Http\Requests;

use App\Support\OfficialBarangays;
use App\Support\StaffAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->role === 'admin');
    }

    public function rules(): array
    {
        $staff = $this->route('user');
        $staffId = is_object($staff) ? $staff->id : $staff;
        $roles = StaffAccess::creatableRoles($this->user());

        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staffId)],
            'role' => ['sometimes', 'required', 'string', Rule::in($roles)],
            'assigned_barangay' => [
                Rule::requiredIf(fn () => $this->input('role') === 'barangay_official'),
                'nullable',
                'string',
                'max:255',
                Rule::in(OfficialBarangays::names()),
            ],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
