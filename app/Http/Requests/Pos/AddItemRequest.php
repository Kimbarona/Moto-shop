<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Authorization and business rules are enforced by the order services.
 */
class AddItemRequest extends FormRequest
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
            'type' => ['required', Rule::in(['part', 'labor', 'package'])],
            'product_id' => ['required_if:type,part', 'nullable', 'integer'],
            'service_id' => ['required_if:type,labor', 'nullable', 'integer'],
            'package_id' => ['required_if:type,package', 'nullable', 'integer'],
            'qty' => ['nullable', 'numeric', 'gt:0', 'max:9999'],
            'mechanic_id' => ['nullable', 'integer'],
        ];
    }
}
