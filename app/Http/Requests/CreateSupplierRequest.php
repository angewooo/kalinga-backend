<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSupplierRequest extends FormRequest
{
    public function rules()
    {
        return [
            'supplier_name' => 'required|string|max:255',
            'contact_person' => 'required|string|max:255',
            'email' => 'required|email',
            'phone_number' => 'required|string|max:20',
        ];
    }
}