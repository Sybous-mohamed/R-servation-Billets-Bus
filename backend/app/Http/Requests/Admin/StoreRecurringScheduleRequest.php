<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurringScheduleRequest extends FormRequest
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

            'default_bus_id' => [
                'required',
                'integer',
                Rule::exists('buses', 'id')
                    ->where('status', 'active'),
            ],

            'departure_time' => [
                'required',
                'date_format:H:i',
            ],

            'starts_on' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],

            'ends_on' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:starts_on',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'weekdays' => [
                'required',
                'array',
                'min:1',
                'max:7',
            ],

            'weekdays.*' => [
                'required',
                'integer',
                'between:1,7',
                'distinct',
            ],
        ];
    }
}
