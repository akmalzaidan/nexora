<?php

namespace App\Http\Requests\Inventory;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $item = $this->route('item');

        return [
            'item_category_id' => ['sometimes', 'required', 'exists:item_categories,id'],
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('items', 'sku')->ignore($item instanceof Item ? $item->id : $item),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['sometimes', 'required', 'string', 'max:30'],
            'minimum_stock' => ['sometimes', 'integer', 'min:0'],
            'maximum_stock' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
