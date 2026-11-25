<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\RecurringSchedule;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurringTripGenerationService
{
    public function __construct(
        private RecurringScheduleConflictService $conflicts
    ) {}

    public function generate(
        int $scheduleId,
        string $fromDate,
        string $toDate
    ): array {

        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $to = CarbonImmutable::parse($toDate)->startOfDay();

        if ($to->lt($from) || $to->gt($from->addDays(30))) {
            throw ValidationException::withMessages([
                'to_date' => 'La période doit être de 31 jours maximum.',
            ]);
        }

        return DB::transaction(function () use (
            $scheduleId,
            $from,
            $to
        ) {

            // 1. Verrouiller le programme
            $schedule = RecurringSchedule::query()
                ->whereKey($scheduleId)
                ->lockForUpdate()
                ->firstOrFail();

            $schedule->load([
                'days',
                'route.routeStops',
            ]);

            if (!$schedule->is_active) {
                throw ValidationException::withMessages([
                    'schedule' => 'Ce programme est inactif.',
                ]);
            }

            // 2. Verrouiller le bus
            $bus = Bus::query()
                ->whereKey($schedule->default_bus_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bus->status !== 'active') {
                throw ValidationException::withMessages([
                    'bus_id' => 'Ce bus est indisponible.',
                ]);
            }

            $route = $schedule->route;

            if (!$route || !$route->is_active) {
                throw ValidationException::withMessages([
                    'route_id' => 'Ce trajet est indisponible.',
                ]);
            }

            // 3. Vérifier les dates du programme
            $scheduleStart = $schedule->starts_on->startOfDay();
            $scheduleEnd = $schedule->ends_on?->startOfDay();

            if (
                $from->lt($scheduleStart) ||
                ($scheduleEnd && $to->gt($scheduleEnd))
            ) {
                throw ValidationException::withMessages([
                    'from_date' =>
                        'La période demandée doit être comprise dans le programme.',
                ]);
            }

            // 4. Récupérer les jours et les arrêts
            $weekdays = $schedule->days
                ->pluck('weekday')
                ->map(fn ($day) => (int) $day)
                ->all();

            if (empty($weekdays)) {
                throw ValidationException::withMessages([
                    'weekdays' => 'Aucun jour programmé.',
                ]);
            }

            $stops = $route->routeStops
                ->sortBy('stop_order')
                ->values();

            if (
                $stops->count() < 2 ||
                (int) $stops->first()->estimated_offset_minutes !== 0
            ) {
                throw ValidationException::withMessages([
                    'route_id' => 'Les arrêts du trajet sont invalides.',
                ]);
            }

            $previousOffset = -1;

            foreach ($stops as $stop) {
                $offset = (int) $stop->estimated_offset_minutes;

                if ($offset <= $previousOffset) {
                    throw ValidationException::withMessages([
                        'route_id' => 'Les durées des arrêts sont invalides.',
                    ]);
                }

                $previousOffset = $offset;
            }

            $duration = $previousOffset;

            if ($duration <= 0 || $duration > 10080) {
                throw ValidationException::withMessages([
                    'route_id' => 'Durée du trajet invalide.',
                ]);
            }

            $created = 0;
            $skipped = 0;
            $tripIds = [];

            // 5. Parcourir les dates
            for (
                $date = $from;
                $date->lte($to);
                $date = $date->addDay()
            ) {

                if (!in_array($date->dayOfWeekIso, $weekdays, true)) {
                    continue;
                }

                $departure = CarbonImmutable::parse(
                    $date->toDateString() . ' ' .
                    $schedule->departure_time
                );

                $arrival = $departure->addMinutes($duration);

                // 6. Éviter les doublons du même programme
                $alreadyExists = Trip::query()
                    ->where('recurring_schedule_id', $schedule->id)
                    ->where(
                        'departure_at',
                        $departure->toDateTimeString()
                    )
                    ->exists();

                if ($alreadyExists) {
                    $skipped++;
                    continue;
                }

                // 7. Vérifier les autres programmes
                $this->conflicts->assertNoConflict(
                    $route->id,
                    $bus->id,
                    $departure->format('H:i'),
                    $date->toDateString(),
                    $date->toDateString(),
                    [$date->dayOfWeekIso],
                    $schedule->id
                );

                // 8. Vérifier les Trips existants
                $hasTripConflict = Trip::query()
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

                if ($hasTripConflict) {
                    throw ValidationException::withMessages([
                        'bus_id' =>
                            'Conflit avec un voyage existant le ' .
                            $date->toDateString(),
                    ]);
                }

                // 9. Créer le voyage
                $trip = Trip::create([
                    'route_id' => $route->id,
                    'recurring_schedule_id' => $schedule->id,
                    'bus_id' => $bus->id,
                    'departure_at' => $departure,
                    'expected_arrival_at' => $arrival,
                    'status' => 'scheduled',
                    'delay_minutes' => 0,
                ]);

                // 10. Créer les arrêts du voyage
                $lastIndex = $stops->count() - 1;

                foreach ($stops as $index => $routeStop) {

                    $stopTime = $departure->addMinutes(
                        (int) $routeStop->estimated_offset_minutes
                    );

                    $trip->tripStops()->create([
                        'route_stop_id' => $routeStop->id,
                        'stop_order' => $routeStop->stop_order,
                        'scheduled_arrival_at' =>
                            $index === 0 ? null : $stopTime,
                        'scheduled_departure_at' =>
                            $index === $lastIndex ? null : $stopTime,
                    ]);
                }

                $created++;
                $tripIds[] = $trip->id;
            }

            return [
                'schedule_id' => $schedule->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'created' => $created,
                'skipped' => $skipped,
                'trip_ids' => $tripIds,
            ];
        });
    }
}
