<?php

namespace App\Http\Requests\Sales;

use App\Models\Sales\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // pos.access is enforced by route middleware, finer rules by SaleService
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            // Walk-in credit customer: registered on the spot (or matched by phone).
            'new_customer' => ['nullable', 'array'],
            'new_customer.name' => ['required_with:new_customer', 'string', 'max:150'],
            'new_customer.phone' => ['required_with:new_customer', 'string', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'new_customer.address' => ['required_with:new_customer', 'string', 'max:255'],
            'price_level' => ['nullable', Rule::in(['retail', 'wholesale'])],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.product_unit_id' => ['nullable', 'integer', Rule::exists('product_units', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
            'payments' => ['present', 'array', 'max:5'],
            'payments.*.method' => ['required', Rule::in(Payment::TILL_METHODS)],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.provider' => ['nullable', 'string', 'max:40'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.payer_phone' => ['nullable', 'string', 'max:30'],
            'payments.*.online_payment_id' => ['nullable', 'required_if:payments.*.method,online', 'integer'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'sold_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'held_sale_id' => ['nullable', 'integer'],
            'send_sms' => ['nullable', 'boolean'],
            'receipt_channels' => ['nullable', 'array'],
            'receipt_channels.*' => ['string', 'in:sms,whatsapp,email'],
            'use_savings' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $seen = [];
            foreach ($this->input('items', []) as $i => $item) {
                $key = ($item['product_id'] ?? '').':'.($item['product_unit_id'] ?? '');
                if (isset($seen[$key])) {
                    $v->errors()->add("items.{$i}.product_id", __('The same product appears twice in the cart. Change the quantity instead.'));
                }
                $seen[$key] = true;
            }
            foreach ($this->input('payments', []) as $i => $payment) {
                if (in_array($payment['method'] ?? null, ['mobile_money', 'bank', 'cheque', 'card'], true) && empty($payment['reference'])) {
                    $v->errors()->add("payments.{$i}.reference", __('Enter the transaction ID / reference for :method payments.', ['method' => __(str_replace('_', ' ', $payment['method']))]));
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required' => __('The cart is empty.'),
            'items.*.quantity.gt' => __('Every quantity must be greater than zero.'),
            'new_customer.phone.regex' => __('Enter a valid phone number, e.g. 0712 345 678.'),
            'new_customer.address.required_with' => __('Enter the customer\'s residential address for a credit sale.'),
        ];
    }
}
