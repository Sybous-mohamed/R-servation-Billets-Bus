<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestCancellationRequest;
use App\Models\Reservation;
use App\Services\CancellationRequestService;
use Illuminate\Http\JsonResponse;

class CancellationRequestController extends Controller
{
    public function store(
        RequestCancellationRequest $request,
        Reservation $reservation,
        CancellationRequestService $service
    ): JsonResponse {

        $data = $request->validated();

        $result = $service->create(
            $reservation->id,
            (int) $request->user()->id,
            $data['reason'] ?? null
        );

        $alreadyRequested = $result['already_requested'];

        return response()->json([
            'message' => $alreadyRequested
                ? 'Demande d annulation deja en attente.'
                : 'Demande d annulation envoyee.',
            'data' => [
                'cancellation_request' => $result['request'],
                'reservation_status' => 'cancellation_requested',
                'already_requested' => $alreadyRequested,
            ],
        ], $alreadyRequested ? 200 : 201);
    }
}
