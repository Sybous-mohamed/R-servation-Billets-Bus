<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTripRequest;
use App\Models\Trip;
use App\Services\TripCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\UpdateTripStatusRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;


class TripController extends Controller
{
    // GET /api/admin/trips
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'route_id' => ['nullable', 'integer'],
            'bus_id' => ['nullable', 'integer'],
            'status' => [
                'nullable',
                'in:scheduled,boarding,in_progress,completed,cancelled'
            ],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = Trip::query()
            ->with(['route', 'bus', 'recurringSchedule'])
            ->withCount('tripStops');

        if (!empty($filters['route_id'])) {
            $query->where('route_id', $filters['route_id']);
        }

        if (!empty($filters['bus_id'])) {
            $query->where('bus_id', $filters['bus_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate(
                'departure_at',
                $filters['date']
            );
        }

        return response()->json(
            $query->orderBy('departure_at')->paginate(10)
        );
    }

    // POST /api/admin/trips
    public function store(
        StoreTripRequest $request,
        TripCreationService $service
    ): JsonResponse {

        $trip = $service->createManual(
            $request->validated()
        );

        return response()->json([
            'message' => 'Voyage créé avec succès.',
            'data' => $trip,
        ], 201);
    }

    // GET /api/admin/trips/{trip}
    public function show(Trip $trip): JsonResponse
    {
        return response()->json([
            'data' => $trip->load([
                'route',
                'bus',
                'recurringSchedule',
                'tripStops' => function ($query) {
                    $query->orderBy('stop_order');
                },
                'tripStops.routeStop.stop',
            ]),
        ]);
    }


    // PATCH /api/admin/trips/{trip}/status
    public function updateStatus(
        UpdateTripStatusRequest $request,
        Trip $trip
    ): JsonResponse {

        $data = $request->validated();

        return DB::transaction(function () use ($trip, $data) {

            // 1. Verrouiller le voyage
            $trip = Trip::query()
                ->whereKey($trip->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatus = $trip->status;
            $newStatus = $data['status'];

            // 2. Transitions autorisées
            $transitions = [
                'scheduled' => ['boarding', 'cancelled'],
                'boarding' => ['in_progress', 'cancelled'],
                'in_progress' => ['completed'],
                'completed' => [],
                'cancelled' => [],
            ];

            if (!in_array(
                $newStatus,
                $transitions[$currentStatus] ?? [],
                true
            )) {
                throw ValidationException::withMessages([
                    'status' =>
                        "Transition interdite : {$currentStatus} vers {$newStatus}.",
                ]);
            }

            // 3. Règles d'annulation
            if ($newStatus === 'cancelled') {

                if (now()->greaterThanOrEqualTo($trip->departure_at)) {
                    throw ValidationException::withMessages([
                        'status' =>
                            'Impossible d annuler après le départ prévu.',
                    ]);
                }

                // Ne pas annuler tant que des réservations
                // sont liées à ce voyage.
                if (DB::table('reservations')
                    ->where('trip_id', $trip->id)
                    ->exists()) {

                    throw ValidationException::withMessages([
                        'status' =>
                            'Ce voyage possède des réservations. Traitez-les avant annulation.',
                    ]);
                }
            }

            // 4. Empêcher un départ réel trop tôt
            if (
                $newStatus === 'in_progress' &&
                now()->lessThan($trip->departure_at)
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Le départ prévu n est pas encore atteint.',
                ]);
            }

            // 5. Préparer les modifications
            $changes = [
                'status' => $newStatus,
            ];

            if ($newStatus === 'in_progress') {
                $changes['actual_departure_at'] = now();
            }

            if ($newStatus === 'completed') {
                $changes['actual_arrival_at'] = now();
            }

            // 6. Enregistrer
            $trip->update($changes);

            return response()->json([
                'message' => 'Statut du voyage modifié avec succès.',
                'data' => $trip->fresh()->load([
                    'route',
                    'bus',
                    'tripStops.routeStop.stop',
                ]),
            ]);
        });
    }

}
