<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExternalBorrowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_gu' => ['nullable', 'string', 'max:150'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'phone' => [
                'required',
                'regex:/^[0-9]{10}$/',
            ],
            'address' => ['nullable', 'string', 'max:1000'],
            'preferred_language' => [
                'sometimes',
                Rule::in(['gu', 'en']),
            ],
        ];
    }
}
