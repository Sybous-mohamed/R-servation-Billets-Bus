<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateRecurringTripsRequest;
use App\Models\RecurringSchedule;
use App\Services\RecurringTripGenerationService;
use Illuminate\Http\JsonResponse;

class RecurringTripGenerationController extends Controller
{
    public function __invoke(
        GenerateRecurringTripsRequest $request,
        RecurringSchedule $recurringSchedule,
        RecurringTripGenerationService $service
    ): JsonResponse {

        $data = $request->validated();

        $result = $service->generate(
            $recurringSchedule->id,
            $data['from_date'],
            $data['to_date']
        );

        return response()->json([
            'message' => 'Génération des voyages terminée.',
            'data' => $result,
        ]);
    }
}
