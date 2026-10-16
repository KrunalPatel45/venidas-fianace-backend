<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayOpeningFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', 'in:cash,upi,bank'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}