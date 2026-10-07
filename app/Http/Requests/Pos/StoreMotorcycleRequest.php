<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Authorization and business rules are enforced by the order services.
 */
class StoreMotorcycleRequest extends FormRequest
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
            'motorcycle_model_id' => ['nullable', 'integer', 'exists:motorcycle_models,id'],
            'plate_no' => ['nullable', 'string', 'max:20', Rule::unique('customer_motorcycles', 'plate_no')],
            'year' => ['nullable', 'integer', 'min:1970', 'max:2100'],
            'color' => ['nullable', 'string', 'max:50'],
            'last_odometer' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Plates are compared upper-case with single spaces, like the model stores them.
     */
    protected function prepareForValidation(): void
    {
        $plate = data_get($this->all(), 'plate_no');

        if (is_string($plate)) {
            $normalized = trim($plate) === '' ? null : strtoupper((string) preg_replace('/\s+/', ' ', trim($plate)));
            $data = $this->all();
            data_set($data, 'plate_no', $normalized);
            $this->replace($data);
        }
    }
}
