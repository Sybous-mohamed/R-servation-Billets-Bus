<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route_name' => [
                'sometimes', 'required', 'string', 'max:150'
            ],

            'is_active' => [
                'sometimes', 'boolean'
            ],

            // Structure modification handled separately
            'stops' => ['prohibited'],
            'fares' => ['prohibited'],
        ];
    }
}
