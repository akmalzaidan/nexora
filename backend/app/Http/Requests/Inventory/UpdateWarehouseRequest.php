<?php

namespace App\Http\Requests\Inventory;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $warehouse = $this->route('warehouse');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('warehouses', 'code')->ignore($warehouse instanceof Warehouse ? $warehouse->id : $warehouse),
            ],
            'location_id' => ['nullable', 'exists:locations,id'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
