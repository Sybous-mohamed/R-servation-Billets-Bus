<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRouteRequest;
use App\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Admin\UpdateRouteRequest;

class RouteController extends Controller
{
    // GET /api/admin/routes
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = Route::query()
            ->with('routeStops.stop')
            ->withCount('routeStops');

        if (!empty($filters['search'])) {
            $query->where(
                'route_name',
                'like',
                '%' . $filters['search'] . '%'
            );
        }

        if (isset($filters['is_active'])) {
            $query->where(
                'is_active',
                (bool) $filters['is_active']
            );
        }

        return response()->json(
            $query->orderByDesc('id')->paginate(10)
        );
    }

    // GET /api/admin/routes/{route}
    public function show(Route $route): JsonResponse
    {
        return response()->json([
            'data' => $this->loadDetails($route),
        ]);
    }

    // POST /api/admin/routes
    public function store(StoreRouteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->validateStructure($data);

        $route = DB::transaction(function () use ($data) {

            $route = Route::create([
                'route_name' => $data['route_name'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $routeStopIds = [];

            // Créer les arrêts dans leur ordre
            foreach ($data['stops'] as $index => $item) {

                $routeStop = $route->routeStops()->create([
                    'stop_id' => $item['stop_id'],
                    'stop_order' => $index + 1,
                    'estimated_offset_minutes' =>
                        $item['estimated_offset_minutes'],
                ]);

                $routeStopIds[$item['stop_id']] =
                    $routeStop->id;
            }

            // Créer les tarifs entre les arrêts
            foreach ($data['fares'] as $fare) {

                $route->routeFares()->create([
                    'from_route_stop_id' =>
                        $routeStopIds[$fare['from_stop_id']],

                    'to_route_stop_id' =>
                        $routeStopIds[$fare['to_stop_id']],

                    'amount' => $fare['amount'],

                    'currency' => $fare['currency'] ?? 'MAD',
                ]);
            }

            return $route;
        });

        return response()->json([
            'message' => 'Trajet créé avec succès.',
            'data' => $this->loadDetails($route),
        ], 201);
    }


    // PATCH /api/admin/routes/{route}
    public function update(
        UpdateRouteRequest $request,
        Route $route
    ): JsonResponse {

        $route->update($request->validated());

        return response()->json([
            'message' => 'Trajet modifié avec succès.',
            'data' => $this->loadDetails($route->fresh()),
        ]);
    }

    // DELETE /api/admin/routes/{route}
    public function destroy(Route $route): JsonResponse
    {
        return DB::transaction(function () use ($route) {

            $route = Route::query()
                ->whereKey($route->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Empêcher suppression d'un trajet planifié
            $hasSchedules = DB::table('recurring_schedules')
                ->where('route_id', $route->id)
                ->exists();

            $hasTrips = DB::table('trips')
                ->where('route_id', $route->id)
                ->exists();

            if ($hasSchedules || $hasTrips) {
                return response()->json([
                    'message' =>
                        'Ce trajet possède une planification ou des voyages. Désactivez-le au lieu de le supprimer.',
                ], 409);
            }

            // Supprimer dans l'ordre des dépendances
            $route->routeFares()->delete();
            $route->routeStops()->delete();
            $route->delete();

            return response()->json([
                'message' => 'Trajet supprimé avec succès.',
            ]);
        });
    }

    private function loadDetails(Route $route): Route
    {
        return $route->load([
            'routeStops.stop',
            'routeFares.fromRouteStop.stop',
            'routeFares.toRouteStop.stop',
        ]);
    }

    private function validateStructure(array $data): void
    {
        $stops = $data['stops'];
        $positions = [];

        // Premier arrêt = minute 0
        if ((int) $stops[0]['estimated_offset_minutes'] !== 0) {
            throw ValidationException::withMessages([
                'stops' => 'Le premier arrêt doit commencer à 0 minute.',
            ]);
        }

        foreach ($stops as $index => $stop) {

            $positions[$stop['stop_id']] = $index;

            // Les durées doivent être croissantes
            if ($index > 0) {
                $previous = (int) $stops[$index - 1]
                    ['estimated_offset_minutes'];

                $current = (int)
                    $stop['estimated_offset_minutes'];

                if ($current <= $previous) {
                    throw ValidationException::withMessages([
                        'stops' =>
                            'Les durées doivent être strictement croissantes.',
                    ]);
                }
            }
        }

        $seenPairs = [];

        foreach ($data['fares'] as $fare) {
            $from = $fare['from_stop_id'];
            $to = $fare['to_stop_id'];

            // Les deux arrêts doivent appartenir au trajet
            if (
                !array_key_exists($from, $positions) ||
                !array_key_exists($to, $positions)
            ) {
                throw ValidationException::withMessages([
                    'fares' =>
                        'Les tarifs doivent utiliser les arrêts du trajet.',
                ]);
            }

            // On ne peut voyager que vers un arrêt suivant
            if ($positions[$from] >= $positions[$to]) {
                throw ValidationException::withMessages([
                    'fares' =>
                        'Le départ doit précéder la destination.',
                ]);
            }

            $key = $from . '-' . $to;

            if (isset($seenPairs[$key])) {
                throw ValidationException::withMessages([
                    'fares' => 'Un tarif est défini deux fois.',
                ]);
            }

            $seenPairs[$key] = true;
        }

        // Chaque paire possible doit avoir un prix
        $count = count($stops);
        $expectedFares = ($count * ($count - 1)) / 2;

        if (count($seenPairs) !== $expectedFares) {
            throw ValidationException::withMessages([
                'fares' =>
                    'Un tarif est obligatoire pour chaque paire d arrêts.',
            ]);
        }
    }
}
