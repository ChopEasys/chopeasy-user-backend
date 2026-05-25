<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules()
    {
        return [
            'fullname' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phoneno' => 'required|string|unique:users,phoneno',
            'user_type' => ['required', Rule::in(['customer', 'vendor', 'rider', 'agent'])],
            'password'  => 'required|string|min:8|confirmed',

            'store_name' => 'nullable|required_if:user_type,vendor|string|max:255',
            'store_image' => 'nullable|string', 
            'cac_certificate' => 'nullable|string',
            
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'vehicle' => ['nullable', 'required_if:user_type,rider', Rule::in(['motorcycle', 'bicycle', 'car'])],
            'bank_name' => 'nullable|string|max:255',
            'lga' => 'nullable|string|max:255',
            'bank_code' => 'nullable|string|max:50',
            'account_number' => 'nullable|string|max:50',
            'account_name' => 'nullable|string|max:255',
            'referral_code' => 'nullable|string|exists:users,id', 
        ];
    }
}
