<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'integer', 'exists:trips,id'],
            'bus_seat_id' => ['required', 'integer', 'exists:bus_seats,id'],

            'from_trip_stop_id' => ['required', 'integer'],
            'to_trip_stop_id' => ['required', 'integer'],

            'passenger_name' => [
                'required',
                'string',
                'max:150',
            ],

            'passenger_phone' => [
                'nullable',
                'string',
                'max:30',
            ],
        ];
    }
}
