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
            'number' => ['required', 'string', 'max:255'],
            'client' => ['nullable', 'string'],
            'cashier' => ['nullable', 'string'],
            'total' => ['required', 'numeric'],
            'discount' => ['nullable', 'numeric'],
            'active' => ['nullable', 'boolean'],
            'sell' => ['nullable', 'boolean'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['required', 'numeric'],
            'items.*.price' => ['required', 'numeric'],
            'items.*.discount' => ['nullable', 'numeric'],
            'items.*.total' => ['required', 'numeric'],

            'payments' => ['nullable', 'array'],
            'payments.*.payment' => ['required_with:payments.*', 'string'],
            'payments.*.value' => ['required_with:payments.*', 'numeric'],
        ];
    }
}
