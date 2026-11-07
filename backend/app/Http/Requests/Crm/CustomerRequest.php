<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-()]{9,20}$/'],
            'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:30'],
            'vrn' => ['nullable', 'string', 'max:40'],
            'customer_type' => ['nullable', Rule::in(['retail', 'wholesale', 'institution'])],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'allow_credit' => ['boolean'],
            'sms_opt_in' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => __('Enter a valid phone number, e.g. 0712 345 678.')];
    }
}
