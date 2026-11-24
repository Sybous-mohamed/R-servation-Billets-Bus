<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStopRequest;
use App\Http\Requests\Admin\UpdateStopRequest;
use App\Models\Stop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StopController extends Controller
{
    // GET /api/admin/stops
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = Stop::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if (
            array_key_exists('is_active', $filters) &&
            $filters['is_active'] !== null
        ) {
            $query->where(
                'is_active',
                (bool) $filters['is_active']
            );
        }

        return response()->json(
            $query->orderBy('city')
                  ->orderBy('name')
                  ->paginate(10)
        );
    }

    // POST /api/admin/stops
    public function store(StoreStopRequest $request): JsonResponse
    {
        $data = $request->validated();

        $data['is_active'] = $data['is_active'] ?? true;

        $stop = Stop::create($data);

        return response()->json([
            'message' => 'Arrêt créé avec succès.',
            'data' => $stop,
        ], 201);
    }

    // GET /api/admin/stops/{stop}
    public function show(Stop $stop): JsonResponse
    {
        return response()->json([
            'data' => $stop,
        ]);
    }

    // PATCH /api/admin/stops/{stop}
    public function update(
        UpdateStopRequest $request,
        Stop $stop
    ): JsonResponse {
        $stop->update($request->validated());

        return response()->json([
            'message' => 'Arrêt modifié avec succès.',
            'data' => $stop->fresh(),
        ]);
    }

    // DELETE /api/admin/stops/{stop}
    public function destroy(Stop $stop): JsonResponse
    {
        if ($stop->routeStops()->exists()) {
            return response()->json([
                'message' => "Cet arrêt est utilisé dans un trajet. Désactivez-le au lieu de le supprimer.",
            ], 409);
        }

        $stop->delete();

        return response()->json([
            'message' => 'Arrêt supprimé avec succès.',
        ]);
    }
}
