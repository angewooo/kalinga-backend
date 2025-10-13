<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RecordSensorReadingRequest extends FormRequest
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
    public function rules(): array
    {
        return [
            'sensor_id' => 'required|exists:real_time_sensors,id',
            'value' => 'required|numeric',
            'timestamp' => 'sometimes|date',
            'metadata' => 'sometimes|array',
            'metadata.battery_level' => 'sometimes|numeric|min:0|max:100',
            'metadata.signal_strength' => 'sometimes|numeric|min:-100|max:0',
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
            'sensor_id.required' => 'Sensor ID is required',
            'sensor_id.exists' => 'Sensor not found',
            'value.required' => 'Sensor value is required',
            'value.numeric' => 'Sensor value must be a number',
            'timestamp.date' => 'Invalid timestamp format',
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