<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * This is a machine-to-machine endpoint (POS terminal), which may not
     * send an "Accept: application/json" header. Force JSON validation-error
     * responses regardless, instead of Laravel's default redirect-back
     * behavior (which falls back to the app root with no Referer).
     */
    public function expectsJson(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shop' => ['nullable', 'integer'],
            'pos' => ['nullable', 'integer'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'card' => ['nullable', 'string', 'max:255'],
            'openDate' => ['required', 'string'],
            'openTime' => ['required', 'string'],
            'closeDate' => ['nullable', 'string'],
            'number' => ['required', 'string', 'max:255'],

            'user' => ['nullable', 'array'],
            'user.id' => ['nullable', 'integer'],
            'user.name' => ['nullable', 'string'],
            'user.text' => ['nullable', 'string'],

            'payments' => ['nullable', 'array'],
            'payments.*.name' => ['required_with:payments.*', 'string'],
            'payments.*.value' => ['required_with:payments.*', 'numeric'],

            'positions' => ['required', 'array', 'min:1'],
            'positions.*.item' => ['required', 'array'],
            'positions.*.item.id' => ['required', 'integer', 'exists:items,id'],
            'positions.*.item.art' => ['nullable', 'string'],
            'positions.*.item.name' => ['nullable', 'string'],
            'positions.*.item.class_code' => ['nullable', 'string'],
            'positions.*.item.package_code' => ['nullable', 'string'],
            'positions.*.labels' => ['nullable', 'array'],
            'positions.*.barcode' => ['nullable', 'string'],
            'positions.*.qty' => ['required', 'numeric'],
            'positions.*.storno' => ['nullable', 'boolean'],
            'positions.*.sum' => ['nullable', 'numeric'],
            'positions.*.sumR' => ['nullable', 'numeric'],
            'positions.*.sumWD' => ['nullable', 'numeric'],
            'positions.*.sumWT' => ['nullable', 'numeric'],
            'positions.*.totalSum' => ['required', 'numeric'],

            'qtyBuys' => ['nullable', 'integer'],
            'qtyPositions' => ['nullable', 'integer'],
            'session' => ['nullable', 'integer'],
            'type' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'sum' => ['nullable', 'numeric'],
            'sumWithDiscs' => ['nullable', 'numeric'],
            'total' => ['required', 'numeric'],
            'aos' => ['nullable', 'array'],
            'fiscal' => ['nullable', 'string'],
        ];
    }
}
