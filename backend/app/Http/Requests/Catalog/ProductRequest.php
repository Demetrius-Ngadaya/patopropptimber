<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware checks products.create / products.update
    }

    protected function prepareForValidation(): void
    {
        // multipart/form-data sends booleans as strings
        foreach (['track_expiry', 'allow_decimal', 'is_service', 'show_online', 'is_active', 'remove_image'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([$flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN)]);
            }
        }
        foreach (['sku', 'barcode', 'wholesale_price', 'wholesale_min_qty', 'min_selling_price', 'category_id', 'unit_id', 'expiry_alert_days'] as $nullable) {
            if ($this->input($nullable) === '') {
                $this->merge([$nullable => null]);
            }
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $isUpdate = $id !== null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-\.]+$/', Rule::unique('products', 'barcode')->ignore($id), Rule::unique('product_units', 'barcode')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'brand' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_sw' => ['nullable', 'string', 'max:5000'],
            'cost_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_min_qty' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0', 'lte:selling_price'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'track_expiry' => ['boolean'],
            'expiry_alert_days' => ['nullable', 'integer', 'min:1', 'max:1095'],
            'allow_decimal' => ['boolean'],
            'is_service' => ['boolean'],
            'show_online' => ['boolean'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['boolean'],
            // Only when creating:
            'opening_quantity' => [$isUpdate ? 'prohibited' : 'nullable', 'numeric', 'min:0'],
            'opening_expiry_date' => ['nullable', 'date', 'after:today'],
            'opening_batch_no' => ['nullable', 'string', 'max:60'],
            // Required when editing so the audit log says why:
            'change_reason' => [$isUpdate ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'change_reason.required' => __('Please give a reason for this change (it is kept in the audit log).'),
            'min_selling_price.lte' => __('The minimum price cannot be higher than the selling price.'),
            'opening_quantity.prohibited' => __('Stock cannot be changed here. Use a stock adjustment or a purchase.'),
        ];
    }
}
