<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GenerateRecurringTripsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],

            'to_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
            ],
        ];
    }
}
