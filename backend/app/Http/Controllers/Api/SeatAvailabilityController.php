<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\SeatAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeatAvailabilityController extends Controller
{
    public function __invoke(
        Request $request,
        Trip $trip,
        SeatAvailabilityService $service
    ): JsonResponse {

        $data = $request->validate([
            'from_trip_stop_id' => ['required', 'integer'],
            'to_trip_stop_id' => ['required', 'integer'],
        ]);

        $result = $service->getAvailability(
            $trip,
            (int) $data['from_trip_stop_id'],
            (int) $data['to_trip_stop_id']
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
