<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecurringScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('routes', 'id')
                    ->where('is_active', true),
            ],

            'default_bus_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('buses', 'id')
                    ->where('status', 'active'),
            ],

            'departure_time' => [
                'sometimes', 'required', 'date_format:H:i',
            ],

            'starts_on' => [
                'sometimes', 'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],

            'ends_on' => [
                'sometimes', 'nullable', 'date_format:Y-m-d',
            ],

            'is_active' => [
                'sometimes', 'boolean',
            ],

            'weekdays' => [
                'sometimes', 'required',
                'array', 'min:1', 'max:7',
            ],

            'weekdays.*' => [
                'required', 'integer',
                'between:1,7', 'distinct',
            ],
        ];
    }
}
