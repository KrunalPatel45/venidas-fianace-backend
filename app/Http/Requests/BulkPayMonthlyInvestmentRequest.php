<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkPayMonthlyInvestmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'investment_ids' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'investment_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:monthly_investments,id',
            ],
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
