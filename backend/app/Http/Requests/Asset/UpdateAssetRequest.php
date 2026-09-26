<?php

namespace App\Http\Requests\Asset;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $asset = $this->route('asset');

        return [
            'asset_category_id' => ['sometimes', 'required', 'exists:asset_categories,id'],
            'asset_code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('assets', 'asset_code')->ignore($asset instanceof Asset ? $asset->id : $asset),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:20', Rule::in(['DRAFT', 'ACTIVE', 'INACTIVE', 'MAINTENANCE', 'RETIRED', 'LOST', 'DISPOSED'])],
            'condition' => ['sometimes', 'string', 'max:20', Rule::in(['GOOD', 'FAIR', 'POOR', 'DAMAGED', 'FAILED'])],
            'purchase_date' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'current_user_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
