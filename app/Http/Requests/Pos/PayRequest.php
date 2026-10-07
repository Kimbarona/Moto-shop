<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Authorization and business rules are enforced by the order services.
 */
class PayRequest extends FormRequest
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
            'payment_method_id' => ['nullable', 'integer'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
