<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route_name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],

            'stops' => ['required', 'array', 'min:2'],

            'stops.*.stop_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('stops', 'id')->where('is_active', true),
            ],

            'stops.*.estimated_offset_minutes' => [
                'required', 'integer', 'min:0',
            ],

            'fares' => ['required', 'array', 'min:1'],

            'fares.*.from_stop_id' => [
                'required', 'integer', 'exists:stops,id',
            ],

            'fares.*.to_stop_id' => [
                'required', 'integer', 'exists:stops,id',
            ],

            'fares.*.amount' => [
                'required', 'numeric', 'gt:0', 'decimal:0,2',
            ],

            'fares.*.currency' => [
                'sometimes', 'required', 'in:MAD',
            ],
        ];
    }
}
