<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function rules()
    {
        return [
            'supplier_name' => 'sometimes|string|max:255',
            'contact_person' => 'sometimes|string|max:255',
            'email' => 'sometimes|email',
            'phone_number' => 'sometimes|string|max:20',
        ];
    }
}