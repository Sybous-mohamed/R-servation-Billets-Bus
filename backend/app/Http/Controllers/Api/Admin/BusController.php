<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBusRequest;
use App\Http\Requests\Admin\UpdateBusRequest;
use App\Models\Bus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
class BusController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => [
                'nullable',
                'in:active,maintenance,out_of_service',
            ],
        ]);

        $buses = Bus::query()
            ->with('equipments')
            ->withCount('seats')
            ->when(
                $filters['search'] ?? null,
                function ($query, $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('registration_number', 'like', "%{$search}%")
                          ->orWhere('bus_name', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, $status) =>
                    $query->where('status', $status)
            )
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json($buses);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBusRequest $request): JsonResponse
    {
        $data = $request->validated();

        $bus = DB::transaction(function () use ($data) {

            $bus = Bus::create(Arr::only($data, [
                'registration_number',
                'bus_name',
                'capacity',
                'status',
            ]));

            // Création automatique des sièges
            $seats = [];
            $now = now();

            for ($i = 1; $i <= $bus->capacity; $i++) {
                $seats[] = [
                    'bus_id' => $bus->id,
                    'seat_number' => (string) $i,
                    'seat_type' => 'standard',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('bus_seats')->insert($seats);

            // Association des équipements
            $bus->equipments()->sync(
                $data['equipments'] ?? []
            );

            return $bus;
        });

        return response()->json([
            'message' => 'Bus créé avec succès.',
            'data' => $bus->load(['seats', 'equipments']),
        ], 201);
    }


    // 3. Détails d'un bus
    public function show(Bus $bus): JsonResponse
    {
        $bus->load([
            'seats' => fn ($query) => $query->orderBy('id'),
            'equipments',
            'maintenances',
        ]);

        return response()->json([
            'data' => $bus,
        ]);
    }

    // 4. Modifier un bus
    public function update(
        UpdateBusRequest $request,
        Bus $bus
    ): JsonResponse {
        $data = $request->validated();

        DB::transaction(function () use ($bus, $data) {

            $bus->update(Arr::only($data, [
                'registration_number',
                'bus_name',
                'status',
            ]));

            if (array_key_exists('equipments', $data)) {
                $bus->equipments()->sync($data['equipments']);
            }
        });

        return response()->json([
            'message' => 'Bus modifié avec succès.',
            'data' => $bus->fresh()->load([
                'seats',
                'equipments',
            ]),
        ]);
    }

    // 5. Supprimer un bus non utilisé
    public function destroy(Bus $bus): JsonResponse
    {
        return DB::transaction(function () use ($bus) {

            $bus = Bus::whereKey($bus->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasTrips = DB::table('trips')
                ->where('bus_id', $bus->id)
                ->exists();

            $hasSchedules = DB::table('recurring_schedules')
                ->where('default_bus_id', $bus->id)
                ->exists();

            $hasMaintenances = DB::table('bus_maintenances')
                ->where('bus_id', $bus->id)
                ->exists();

            if (
                $hasTrips ||
                $hasSchedules ||
                $hasMaintenances
            ) {
                return response()->json([
                    'message' =>
                        'Ce bus possède un historique ou une planification. Désactivez-le au lieu de le supprimer.',
                ], 409);
            }

            $bus->equipments()->detach();
            $bus->seats()->delete();
            $bus->delete();

            return response()->json([
                'message' => 'Bus supprimé avec succès.',
            ]);
        });
    }
}
