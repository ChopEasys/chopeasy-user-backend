<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreatePricingConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Add authorization logic as needed
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'base_charge' => 'required|numeric|min:0',  // Base fee (handling, packaging, dispatch)
            'base_fee_threshold' => 'nullable|numeric|min:0',  // Min subtotal for base fee to apply
            'no_base_fee_platform_percentage' => 'nullable|numeric|min:0|max:100', // Platform % when no base fee
            'service_charge' => 'nullable|numeric|min:0',
            'service_fee_percent' => 'nullable|numeric|min:0|max:100',
            'product_markup_percent' => 'nullable|numeric|min:0|max:100',
            'vendor_take_percent' => 'nullable|numeric|min:0|max:100',
            'charge_per_distance' => 'nullable|numeric|min:0',
            'referral_bonus_percentage' => 'nullable|numeric|min:0|max:100',
            'region_id' => 'required|string|max:50',
            'currency' => 'nullable|string|in:NGN,USD',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Configuration name is required',
            'base_charge.required' => 'Base fee is required',
            'base_charge.min' => 'Base fee must be at least 0',
            'region_id.required' => 'Region ID is required',
        ];
    }
}
