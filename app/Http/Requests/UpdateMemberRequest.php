<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        $member = $this->route('member');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'name_gu' => ['sometimes', 'nullable', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($member->id),
            ],
            'phone' => [
                'nullable',
                'digits_between:10,15',
                Rule::unique('users', 'phone')->ignore($member->id),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'preferred_language' => ['sometimes', 'required', 'in:gu,en'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}