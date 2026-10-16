<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExternalBorrowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        $borrower = $this->route('externalBorrower');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'name_gu' => ['sometimes', 'nullable', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($borrower?->id),
            ],
            'phone' => [
                'sometimes',
                'required',
                'regex:/^[0-9]{10}$/',
            ],
            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
            'preferred_language' => [
                'sometimes',
                Rule::in(['gu', 'en']),
            ],
        ];
    }
}
