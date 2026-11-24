<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusRequest extends FormRequest
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
            'registration_number' => [
                'required',
                'string',
                'max:50',
                'unique:buses,registration_number',
            ],

            'bus_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'capacity' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'active',
                    'maintenance',
                    'out_of_service',
                ]),
            ],

            'equipments' => [
                'sometimes',
                'array',
            ],

            'equipments.*' => [
                'integer',
                'distinct',
                'exists:equipments,id',
            ],
        ];
    }
}
