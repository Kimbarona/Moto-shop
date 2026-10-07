<?php

namespace App\Http\Requests\Pos;

use App\Enums\Disposition;
use App\Enums\ReturnReason;
use App\Enums\ReturnResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Quantities, dispositions, the PIN and authorization are enforced by
 * ReturnService.
 */
class StoreReturnRequest extends FormRequest
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
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'resolution' => ['required', Rule::enum(ReturnResolution::class)],
            'refund_method_id' => ['nullable', 'integer'],
            'refund_reference' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0', 'max:9999'],
            'items.*.disposition' => ['nullable', Rule::enum(Disposition::class)],
            'pin' => ['nullable', 'string', 'max:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Pick a reason for the return.',
            'resolution.required' => 'Choose refund or exchange.',
            'items.required' => 'Pick at least one item to return.',
        ];
    }
}
