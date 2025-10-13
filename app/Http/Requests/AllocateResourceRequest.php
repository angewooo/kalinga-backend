<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class AllocateResourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // For demo, allow all requests
        // In production: return $this->user()->can('allocate-resources');
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_id' => 'required|exists:request_entries,id',
            'allocated_hospital_id' => 'sometimes|exists:hospitals,id',
            'allocated_quantity' => 'sometimes|integer|min:1',
            'algorithm' => 'sometimes|string|in:nearest_hospital,highest_stock,balanced,manual',
            'priority_factors' => 'sometimes|array',
            'priority_factors.distance_weight' => 'sometimes|numeric|min:0|max:1',
            'priority_factors.stock_weight' => 'sometimes|numeric|min:0|max:1',
            'priority_factors.urgency_weight' => 'sometimes|numeric|min:0|max:1',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'request_id.required' => 'Request ID is required',
            'request_id.exists' => 'Request not found',
            'allocated_hospital_id.exists' => 'Hospital not found',
            'allocated_quantity.min' => 'Allocated quantity must be at least 1',
            'algorithm.in' => 'Invalid allocation algorithm',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'The given data was invalid.',
                'details' => $validator->errors()
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1'
            ]
        ], 422));
    }
}