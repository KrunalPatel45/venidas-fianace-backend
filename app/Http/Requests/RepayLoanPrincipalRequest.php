<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepayLoanPrincipalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0.01',
                'max:999999999999.99',
            ],
            'paid_at' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            'payment_method' => [
                'required',
                Rule::in(['cash']),
            ],
            'idempotency_key' => [
                'required',
                'uuid',
            ],
        ];
    }
}
