<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'name_gu' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'digits_between:10,15', 'unique:users,phone'],
            'address' => ['nullable', 'string', 'max:500'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'preferred_language' => ['required', 'in:gu,en'],
        ];
    }
}