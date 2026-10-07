<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Authorization and business rules are enforced by the order services.
 */
class CheckoutRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.type' => ['required', Rule::in(['part', 'labor', 'package'])],
            'lines.*.product_id' => ['required_if:lines.*.type,part', 'nullable', 'integer'],
            'lines.*.service_id' => ['required_if:lines.*.type,labor', 'nullable', 'integer'],
            'lines.*.package_id' => ['required_if:lines.*.type,package', 'nullable', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'gt:0', 'max:9999'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.discount_type' => ['nullable', Rule::in(['amount', 'percent'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.mechanic_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_motorcycle_id' => ['nullable', 'integer', 'exists:customer_motorcycles,id'],
            'odometer_in' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'complaint' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'mechanic_ids' => ['sometimes', 'array'],
            'mechanic_ids.*' => ['integer'],
            'discount_type' => ['nullable', Rule::in(['amount', 'percent'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'payment_method_id' => ['nullable', 'integer'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'max:6'],
            'exchange_return_id' => ['nullable', 'integer'],
        ];
    }
}
