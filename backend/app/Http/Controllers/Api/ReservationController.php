<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Services\ReservationCreationService;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function store(
        StoreReservationRequest $request,
        ReservationCreationService $service
    ): JsonResponse {

        $reservation = $service->create(
            $request->validated(),
            (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Réservation créée. Paiement en attente.',
            'data' => $reservation,
        ], 201);
    }
}
