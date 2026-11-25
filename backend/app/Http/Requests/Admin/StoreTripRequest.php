<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route_id' => [
                'required',
                'integer',
                Rule::exists('routes', 'id')
                    ->where('is_active', true),
            ],

            'bus_id' => [
                'required',
                'integer',
                Rule::exists('buses', 'id')
                    ->where('status', 'active'),
            ],

            'departure_at' => [
                'required',
                'date_format:Y-m-d H:i',
                'after:now',
            ],
        ];
    }
}
