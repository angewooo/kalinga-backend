<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateRequestEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // For demo, allow all requests
        // In production: return $this->user()->can('create-requests');
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
            'requesting_hospital_id' => 'required|exists:hospitals,id',
            'resource_type' => 'required|string|max:100',
            'quantity_needed' => 'required|integer|min:1',
            'urgency_level' => 'required|string|in:low,medium,high,critical',
            'reason' => 'required|string|max:1000',
            'estimated_arrival' => 'required|date|after:now',
            'contact_person' => 'sometimes|string|max:255',
            'contact_number' => 'sometimes|string|max:20',
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
            'requesting_hospital_id.required' => 'Hospital ID is required',
            'requesting_hospital_id.exists' => 'Hospital not found',
            'resource_type.required' => 'Resource type is required',
            'quantity_needed.required' => 'Quantity is required',
            'quantity_needed.min' => 'Quantity must be at least 1',
            'urgency_level.required' => 'Urgency level is required',
            'urgency_level.in' => 'Urgency level must be: low, medium, high, or critical',
            'reason.required' => 'Reason for request is required',
            'estimated_arrival.required' => 'Estimated arrival time is required',
            'estimated_arrival.after' => 'Estimated arrival must be in the future',
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