<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Weekday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPromotionsRequest extends FormRequest
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
            'wallet' => ['sometimes', 'array'],
            'wallet.*' => ['string', 'exists:wallets,slug'],
            'merchant_id' => ['sometimes', 'array'],
            'merchant_id.*' => ['integer', 'exists:merchants,id'],
            'promotion_category_id' => ['sometimes', 'array'],
            'promotion_category_id.*' => ['integer', 'exists:promotion_categories,id'],
            'valid_days' => ['sometimes', 'array'],
            'valid_days.*' => ['string', Rule::enum(Weekday::class)],
            'is_active' => ['sometimes', 'boolean'],
            'valid_on' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
