<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'borrower_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'loan_type' => [
                'required',
                Rule::in(['member', 'external']),
            ],
            'principal_amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0.01',
                'max:999999999999.99',
            ],
            'disbursed_at' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            'disbursement_method' => [
                'required',
                Rule::in(['cash']),
            ],
            'idempotency_key' => [
                'required',
                'uuid',
            ],
            'purpose' => [
                'nullable',
                'string',
                'max:500',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }
}
