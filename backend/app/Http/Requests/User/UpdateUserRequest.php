<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user')?->getKey();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')],
            'department_id' => [
                'nullable',
                'sometimes',
                'integer',
                Rule::exists('departments', 'id'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
