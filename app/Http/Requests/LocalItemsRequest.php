<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LocalItemsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The request body is a bare JSON array of items, not wrapped in an
     * object. Wrap it under a synthetic "items" key so the rest of the
     * validation rules can use normal "items.*.foo" dot notation.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $items = $this->json() ? $this->json()->all() : $this->all();

        // 1C sends `package_code` as a bare JSON number in practice even
        // though it's semantically a code (stored as a string column), so
        // normalize it before the "string" validation rule runs.
        $items = array_map(function ($item) {
            if (is_array($item) && array_key_exists('package_code', $item) && $item['package_code'] !== null) {
                $item['package_code'] = (string) $item['package_code'];
            }

            return $item;
        }, $items);

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:200'],

            'items.*.id' => ['required', 'integer'],

            'items.*.group' => ['nullable', 'array'],
            'items.*.group.id' => ['required_with:items.*.group', 'integer'],
            'items.*.group.name' => ['nullable', 'string', 'max:50'],

            'items.*.mark' => ['nullable', 'string', 'max:20'],
            'items.*.name' => ['required', 'string', 'max:100'],

            'items.*.barcode' => ['nullable', 'array'],
            'items.*.barcode.*' => ['string', 'max:50'],

            'items.*.qty' => ['nullable', 'array'],
            'items.*.qty.*.shop.id' => ['required', 'integer'],
            'items.*.qty.*.shop.name' => ['nullable', 'string'],
            'items.*.qty.*.value' => ['required', 'numeric'],

            'items.*.price' => ['nullable', 'array'],
            'items.*.price.*.price.id' => ['required', 'integer'],
            'items.*.price.*.price.name' => ['nullable', 'string'],
            'items.*.price.*.value' => ['required', 'numeric'],

            'items.*.order' => ['nullable', 'array'],
            'items.*.order.*.shop.id' => ['required', 'integer'],
            'items.*.order.*.min' => ['required', 'numeric'],
            'items.*.order.*.max' => ['required', 'numeric'],

            'items.*.class_code' => ['nullable', 'string', 'max:25'],
            'items.*.package_code' => ['nullable', 'string', 'max:20'],
        ];
    }
}
