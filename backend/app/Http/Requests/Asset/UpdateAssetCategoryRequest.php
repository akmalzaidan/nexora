<?php

namespace App\Http\Requests\Asset;

use App\Models\AssetCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetCategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('assetCategory');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('asset_categories', 'code')->ignore($category instanceof AssetCategory ? $category->id : $category),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
