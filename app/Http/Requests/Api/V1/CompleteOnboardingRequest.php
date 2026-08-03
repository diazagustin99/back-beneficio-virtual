<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteOnboardingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:191'],
            'merchant_ids' => ['sometimes', 'array'],
            'merchant_ids.*' => ['integer', 'exists:merchants,id'],
            'wallet_ids' => ['sometimes', 'array'],
            'wallet_ids.*' => ['integer', 'exists:wallets,id'],
            'wants_notifications' => ['sometimes', 'boolean'],
        ];
    }
}
