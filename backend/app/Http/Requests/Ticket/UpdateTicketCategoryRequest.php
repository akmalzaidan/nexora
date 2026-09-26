<?php

namespace App\Http\Requests\Ticket;

use App\Models\TicketCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketCategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('ticketCategory');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('ticket_categories', 'code')->ignore($category instanceof TicketCategory ? $category->id : $category),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
