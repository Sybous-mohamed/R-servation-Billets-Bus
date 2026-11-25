<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\Route;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripCreationService
{
    public function __construct(
        private RecurringScheduleConflictService $conflicts
    ) {}

    public function createManual(array $data): Trip
    {
        return DB::transaction(function () use ($data) {

            // 1. Verrouiller le Bus
            $bus = Bus::query()
                ->whereKey($data['bus_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($bus->status !== 'active') {
                throw ValidationException::withMessages([
                    'bus_id' => 'Ce bus est indisponible.',
                ]);
            }

            // 2. Charger Route et arrêts
            $route = Route::with('routeStops')
                ->findOrFail($data['route_id']);

            if (!$route->is_active) {
                throw ValidationException::withMessages([
                    'route_id' => 'Ce trajet est inactif.',
                ]);
            }

            $stops = $route->routeStops
                ->sortBy('stop_order')
                ->values();

            if ($stops->count() < 2) {
                throw ValidationException::withMessages([
                    'route_id' =>
                        'Le trajet doit contenir au moins deux arrêts.',
                ]);
            }

            if ((int) $stops->first()->estimated_offset_minutes !== 0) {
                throw ValidationException::withMessages([
                    'route_id' => 'Premier arrêt invalide.',
                ]);
            }

            $duration = (int)
                $stops->last()->estimated_offset_minutes;

            if ($duration <= 0 || $duration > 10080) {
                throw ValidationException::withMessages([
                    'route_id' => 'Durée du trajet invalide.',
                ]);
            }

            // 3. Calcul des heures
            $departure = CarbonImmutable::parse(
                $data['departure_at']
            );

            $arrival = $departure->addMinutes($duration);

            // 4. Vérifier les programmes récurrents
            $this->conflicts->assertNoConflict(
                $route->id,
                $bus->id,
                $departure->format('H:i'),
                $departure->toDateString(),
                $departure->toDateString(),
                [$departure->dayOfWeekIso]
            );

            // 5. Vérifier les Trips existants
            $hasConflict = Trip::query()
                ->where('bus_id', $bus->id)
                ->where('status', '!=', 'cancelled')
                ->where(
                    'departure_at',
                    '<',
                    $arrival->toDateTimeString()
                )
                ->where(
                    'expected_arrival_at',
                    '>',
                    $departure->toDateTimeString()
                )
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'bus_id' =>
                        'Ce bus possède déjà un voyage sur cet horaire.',
                ]);
            }

            // 6. Créer le Trip
            $trip = Trip::create([
                'route_id' => $route->id,
                'recurring_schedule_id' => null,
                'bus_id' => $bus->id,
                'departure_at' => $departure,
                'expected_arrival_at' => $arrival,
                'status' => 'scheduled',
                'delay_minutes' => 0,
            ]);

            // 7. Créer les arrêts du voyage
            $lastIndex = $stops->count() - 1;

            foreach ($stops as $index => $routeStop) {

                $stopTime = $departure->addMinutes(
                    (int) $routeStop->estimated_offset_minutes
                );

                $trip->tripStops()->create([
                    'route_stop_id' => $routeStop->id,
                    'stop_order' => $routeStop->stop_order,

                    // Premier arrêt : pas d'arrivée
                    'scheduled_arrival_at' =>
                        $index === 0 ? null : $stopTime,

                    // Dernier arrêt : pas de départ
                    'scheduled_departure_at' =>
                        $index === $lastIndex ? null : $stopTime,
                ]);
            }

            return $trip->load([
                'route',
                'bus',
                'tripStops.routeStop.stop',
            ]);
        });
    }
}
