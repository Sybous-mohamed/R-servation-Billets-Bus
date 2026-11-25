<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRecurringScheduleRequest;
use App\Models\RecurringSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Admin\UpdateRecurringScheduleRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use App\Models\Bus;
use App\Models\Route;
use App\Services\RecurringScheduleConflictService;

class RecurringScheduleController extends Controller
{
    // GET /api/admin/recurring-schedules
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'route_id' => ['nullable', 'integer', 'exists:routes,id'],
            'default_bus_id' => ['nullable', 'integer', 'exists:buses,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = RecurringSchedule::query()
            ->with(['route', 'bus', 'days']);

        if (!empty($filters['route_id'])) {
            $query->where('route_id', $filters['route_id']);
        }

        if (!empty($filters['default_bus_id'])) {
            $query->where(
                'default_bus_id',
                $filters['default_bus_id']
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

    // POST /api/admin/recurring-schedules
    public function store(
        StoreRecurringScheduleRequest $request,
        RecurringScheduleConflictService $conflicts
    ): JsonResponse {

        $data = $request->validated();

        $schedule = DB::transaction(function () use ($data, $conflicts) {

            // Verrouiller le Bus pendant la vérification et création
            $bus = Bus::query()
                ->whereKey($data['default_bus_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $isActive = $data['is_active'] ?? true;

            if ($isActive) {

                if ($bus->status !== 'active') {
                    throw ValidationException::withMessages([
                        'default_bus_id' => 'Ce bus est indisponible.',
                    ]);
                }

                if (!Route::query()
                    ->whereKey($data['route_id'])
                    ->where('is_active', true)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'route_id' => 'Ce trajet est indisponible.',
                    ]);
                }

                // Vérifier les conflits AVANT de créer
                $conflicts->assertNoConflict(
                    (int) $data['route_id'],
                    (int) $data['default_bus_id'],
                    $data['departure_time'],
                    $data['starts_on'],
                    $data['ends_on'] ?? null,
                    $data['weekdays']
                );


                $conflicts->assertNoTripConflict(
                    (int) $data['route_id'],
                    (int) $data['default_bus_id'],
                    $data['departure_time'],
                    $data['starts_on'],
                    $data['ends_on'] ?? null,
                    $data['weekdays']
                );

            }

            // Créer le programme
            $schedule = RecurringSchedule::create([
                'route_id' => $data['route_id'],
                'default_bus_id' => $data['default_bus_id'],
                'departure_time' => $data['departure_time'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => $isActive,
            ]);

            // Créer les jours
            $days = [];

            foreach ($data['weekdays'] as $weekday) {
                $days[] = [
                    'recurring_schedule_id' => $schedule->id,
                    'weekday' => $weekday,
                ];
            }

            DB::table('recurring_schedule_days')->insert($days);

            return $schedule;
        });

        return response()->json([
            'message' => 'Programme créé avec succès.',
            'data' => $this->loadDetails($schedule),
        ], 201);
    }

    // GET /api/admin/recurring-schedules/{id}
    public function show(
        RecurringSchedule $recurringSchedule
    ): JsonResponse {

        return response()->json([
            'data' => $this->loadDetails($recurringSchedule),
        ]);
    }

    

    public function update(
        UpdateRecurringScheduleRequest $request,
        RecurringSchedule $recurringSchedule,
        RecurringScheduleConflictService $conflicts
    ): JsonResponse {

        $data = $request->validated();

        return DB::transaction(function () use (
            $recurringSchedule,
            $data,
            $conflicts
        ) {

            $schedule = RecurringSchedule::query()
                ->whereKey($recurringSchedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            $busId = (int) (
                $data['default_bus_id'] ?? $schedule->default_bus_id
            );

            $bus = Bus::query()
                ->whereKey($busId)
                ->lockForUpdate()
                ->firstOrFail();

            $structuralFields = [
                'route_id',
                'default_bus_id',
                'departure_time',
                'starts_on',
                'ends_on',
                'weekdays',
            ];

            $hasStructuralChanges = count(
                array_intersect(
                    array_keys($data),
                    $structuralFields
                )
            ) > 0;

            if (
                $hasStructuralChanges &&
                DB::table('trips')
                    ->where('recurring_schedule_id', $schedule->id)
                    ->exists()
            ) {
                return response()->json([
                    'message' =>
                        'Ce programme possède des voyages. Modification de la structure interdite.',
                ], 409);
            }

            // Récupérer les valeurs finales après modification
            $routeId = (int) (
                $data['route_id'] ?? $schedule->route_id
            );

            $departureTime = $data['departure_time']
                ?? $schedule->departure_time;

            $start = $data['starts_on']
                ?? $schedule->starts_on->toDateString();

            $end = array_key_exists('ends_on', $data)
                ? $data['ends_on']
                : $schedule->ends_on?->toDateString();

            $isActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $schedule->is_active;

            $weekdays = $data['weekdays']
                ?? DB::table('recurring_schedule_days')
                    ->where('recurring_schedule_id', $schedule->id)
                    ->orderBy('weekday')
                    ->pluck('weekday')
                    ->map(fn ($day) => (int) $day)
                    ->all();

            if ($end !== null && $end < $start) {
                throw ValidationException::withMessages([
                    'ends_on' =>
                        'La date de fin doit être après la date de début.',
                ]);
            }

            if ($isActive) {

                if ($bus->status !== 'active') {
                    throw ValidationException::withMessages([
                        'default_bus_id' => 'Ce bus est indisponible.',
                    ]);
                }

                if (!Route::query()
                    ->whereKey($routeId)
                    ->where('is_active', true)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'route_id' => 'Ce trajet est indisponible.',
                    ]);
                }

                if (empty($weekdays)) {
                    throw ValidationException::withMessages([
                        'weekdays' => 'Choisissez au moins un jour.',
                    ]);
                }

                // Vérifier les conflits avec les autres programmes
                $conflicts->assertNoConflict(
                    $routeId,
                    $busId,
                    $departureTime,
                    $start,
                    $end,
                    $weekdays,
                    $schedule->id
                );

                $conflicts->assertNoTripConflict(
                    $routeId,
                    $busId,
                    $departureTime,
                    $start,
                    $end,
                    $weekdays,
                    $schedule->id
                );
            }

            $schedule->update(Arr::only($data, [
                'route_id',
                'default_bus_id',
                'departure_time',
                'starts_on',
                'ends_on',
                'is_active',
            ]));

            if (array_key_exists('weekdays', $data)) {

                DB::table('recurring_schedule_days')
                    ->where('recurring_schedule_id', $schedule->id)
                    ->delete();

                $days = [];

                foreach ($data['weekdays'] as $weekday) {
                    $days[] = [
                        'recurring_schedule_id' => $schedule->id,
                        'weekday' => $weekday,
                    ];
                }

                DB::table('recurring_schedule_days')->insert($days);
            }

            return response()->json([
                'message' => 'Programme modifié avec succès.',
                'data' => $this->loadDetails($schedule->fresh()),
            ]);
        });
    }



    // DELETE /api/admin/recurring-schedules/{recurring_schedule}
    public function destroy(
        RecurringSchedule $recurringSchedule
    ): JsonResponse {

        return DB::transaction(function () use ($recurringSchedule) {

            // Verrouiller le programme
            $schedule = RecurringSchedule::query()
                ->whereKey($recurringSchedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Vérifier les voyages liés au programme
            $hasTrips = DB::table('trips')
                ->where('recurring_schedule_id', $schedule->id)
                ->exists();

            if ($hasTrips) {
                return response()->json([
                    'message' =>
                        'Ce programme possède des voyages. Désactivez-le au lieu de le supprimer.',
                ], 409);
            }

            // Supprimer les jours du programme
            DB::table('recurring_schedule_days')
                ->where('recurring_schedule_id', $schedule->id)
                ->delete();

            // Supprimer le programme
            $schedule->delete();

            return response()->json([
                'message' => 'Programme supprimé avec succès.',
            ]);
        });
    }

    private function loadDetails(
        RecurringSchedule $schedule
    ): RecurringSchedule {

        return $schedule->load([
            'route.routeStops.stop',
            'bus',
            'days' => function ($query) {
                $query->orderBy('weekday');
            },
        ]);
    }
}
