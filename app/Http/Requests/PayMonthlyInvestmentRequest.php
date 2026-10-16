<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayMonthlyInvestmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => [
                'required',
                Rule::in(['cash']),
            ],

            'paid_at' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
        ];
    }
}
