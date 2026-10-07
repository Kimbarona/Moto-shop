<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Authorization and business rules are enforced by the order services.
 */
class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qty' => ['sometimes', 'numeric', 'gt:0', 'max:9999'],
            'unit_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'discount_type' => ['sometimes', 'nullable', Rule::in(['amount', 'percent'])],
            'discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'mechanic_id' => ['sometimes', 'nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:6'],
        ];
    }
}
