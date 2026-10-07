<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Authorization and business rules are enforced by the order services.
 */
class UpdateOrderRequest extends FormRequest
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
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_motorcycle_id' => ['nullable', 'integer', 'exists:customer_motorcycles,id'],
            'odometer_in' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'complaint' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'mechanic_ids' => ['sometimes', 'array'],
            'mechanic_ids.*' => ['integer'],
        ];
    }
}
