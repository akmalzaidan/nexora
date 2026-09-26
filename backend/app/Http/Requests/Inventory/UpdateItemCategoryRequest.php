<?php

namespace App\Http\Requests\Inventory;

use App\Models\ItemCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemCategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $itemCategory = $this->route('itemCategory');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('item_categories', 'code')->ignore($itemCategory instanceof ItemCategory ? $itemCategory->id : $itemCategory),
            ],
            'description' => ['nullable', 'string'],
        ];
    }
}
