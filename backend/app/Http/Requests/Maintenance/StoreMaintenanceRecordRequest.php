<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRecordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'maintenance_request_id' => ['required', 'integer'],
            'description' => ['required', 'string'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'result' => ['nullable', 'string'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'technician_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
