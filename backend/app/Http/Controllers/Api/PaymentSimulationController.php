<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\PaymentSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentSimulationController extends Controller
{
    public function __invoke(
        Request $request,
        Reservation $reservation,
        PaymentSimulationService $service
    ): JsonResponse {

        $result = $service->pay(
            $reservation->id,
            (int) $request->user()->id
        );

        return response()->json([
            'message' =>
                'Paiement simulé et réservation confirmée.',
            'data' => $result,
        ]);
    }
}
