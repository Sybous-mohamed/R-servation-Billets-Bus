<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewCancellationRequest;
use App\Models\CancellationRequest;
use App\Services\CancellationReviewService;
use Illuminate\Http\JsonResponse;

class CancellationReviewController extends Controller
{
    // Liste des demandes en attente
    public function index(): JsonResponse
    {
        $requests = CancellationRequest::query()
            ->with('reservation')
            ->where('status', 'pending')
            ->orderBy('id')
            ->paginate(10);

        return response()->json($requests);
    }

    // Approuver ou refuser
    public function review(
        ReviewCancellationRequest $request,
        CancellationRequest $cancellationRequest,
        CancellationReviewService $service
    ): JsonResponse {

        $data = $request->validated();

        $result = $service->review(
            $cancellationRequest->id,
            (int) $request->user()->id,
            $data['decision']
        );

        return response()->json([
            'message' => 'Demande traitee avec succes.',
            'data' => $result,
        ]);
    }
}
