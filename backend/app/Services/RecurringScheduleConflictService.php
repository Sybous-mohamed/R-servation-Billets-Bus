<?php

namespace App\Services;

use App\Models\Route;
use App\Models\RecurringSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class RecurringScheduleConflictService
{
    public function assertNoConflict(
        int $routeId,
        int $busId,
        string $departureTime,
        string $startsOn,
        ?string $endsOn,
        array $weekdays,
        ?int $excludeScheduleId = null
    ): void {

        $route = Route::with('routeStops')
            ->findOrFail($routeId);

        $duration = $this->duration($route);

        $candidate = [
            'departure_time' => $departureTime,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'weekdays' => array_map('intval', $weekdays),
            'duration' => $duration,
        ];

        $existingSchedules = RecurringSchedule::query()
            ->with(['days', 'route.routeStops'])
            ->where('default_bus_id', $busId)
            ->where('is_active', true)
            ->when(
                $excludeScheduleId,
                fn ($query) => $query->where(
                    'id', '!=', $excludeScheduleId
                )
            )
            ->get();

        foreach ($existingSchedules as $schedule) {

            $existing = [
                'departure_time' => $schedule->departure_time,
                'starts_on' =>
                    $schedule->starts_on->toDateString(),
                'ends_on' =>
                    $schedule->ends_on?->toDateString(),
                'weekdays' =>
                    $schedule->days->pluck('weekday')
                        ->map(fn ($day) => (int) $day)
                        ->all(),
                'duration' => $this->duration($schedule->route),
            ];

            if ($this->overlaps($candidate, $existing)) {
                throw ValidationException::withMessages([
                    'default_bus_id' =>
                        'Conflit horaire avec le programme ID '
                        . $schedule->id
                        . '. Ce bus est déjà occupé.',
                ]);
            }
        }
    }


    public function assertNoTripConflict(
        int $routeId,
        int $busId,
        string $departureTime,
        string $startsOn,
        ?string $endsOn,
        array $weekdays,
        ?int $excludeScheduleId = null
    ): void {

        $route = Route::with('routeStops')->findOrFail($routeId);
        $duration = $this->duration($route);

        $startDate = CarbonImmutable::parse($startsOn)->startOfDay();

        $endDate = $endsOn
            ? CarbonImmutable::parse($endsOn)->startOfDay()
            : null;

        $query = DB::table('trips')
            ->where('bus_id', $busId)
            ->where('status', '!=', 'cancelled')
            ->where(
                'expected_arrival_at',
                '>',
                $startDate->toDateTimeString()
            );

        if ($endDate !== null) {
            $latestArrival = $endDate
                ->endOfDay()
                ->addMinutes($duration);

            $query->where(
                'departure_at',
                '<',
                $latestArrival->toDateTimeString()
            );
        }

        // Ignorer les Trips du programme qu'on modifie
        if ($excludeScheduleId !== null) {
            $query->where(function ($q) use ($excludeScheduleId) {
                $q->whereNull('recurring_schedule_id')
                ->orWhere(
                    'recurring_schedule_id',
                    '!=',
                    $excludeScheduleId
                );
            });
        }

        $weekdays = array_map('intval', $weekdays);

        foreach ($query->get() as $trip) {

            $tripStart = CarbonImmutable::parse($trip->departure_at);
            $tripEnd = CarbonImmutable::parse($trip->expected_arrival_at);

            if ($tripEnd->lte($tripStart)) {
                throw ValidationException::withMessages([
                    'default_bus_id' =>
                        'Horaires invalides pour le voyage ID '
                        . $trip->id,
                ]);
            }

            // Dates de depart possibles autour du voyage
            $first = $tripStart
                ->subMinutes($duration)
                ->startOfDay();

            if ($first->lt($startDate)) {
                $first = $startDate;
            }

            $last = $tripEnd->startOfDay();

            if ($endDate !== null && $last->gt($endDate)) {
                $last = $endDate;
            }

            for (
                $date = $first;
                $date->lte($last);
                $date = $date->addDay()
            ) {
                if (!in_array($date->dayOfWeekIso, $weekdays, true)) {
                    continue;
                }

                $candidateStart = CarbonImmutable::parse(
                    $date->toDateString() . ' ' . $departureTime
                );

                $candidateEnd = $candidateStart
                    ->addMinutes($duration);

                if (
                    $candidateStart->lt($tripEnd) &&
                    $tripStart->lt($candidateEnd)
                ) {
                    throw ValidationException::withMessages([
                        'default_bus_id' =>
                            'Conflit avec le voyage ID '
                            . $trip->id
                            . '. Ce bus est déjà occupé.',
                    ]);
                }
            }
        }
    }


    private function duration(Route $route): int
    {
        $minutes = (int) $route->routeStops
            ->max('estimated_offset_minutes');

        if ($minutes <= 0 || $minutes > 10080) {
            throw ValidationException::withMessages([
                'route_id' =>
                    'Durée du trajet invalide (maximum 7 jours).',
            ]);
        }

        return $minutes;
    }

    private function overlaps(array $a, array $b): bool
    {
        $aStart = CarbonImmutable::parse($a['starts_on']);
        $bStart = CarbonImmutable::parse($b['starts_on']);

        $aEnd = $a['ends_on']
            ? CarbonImmutable::parse($a['ends_on'])
            : null;

        $bEnd = $b['ends_on']
            ? CarbonImmutable::parse($b['ends_on'])
            : null;

        // Rechercher les départs voisins,
        // y compris ceux qui traversent minuit.
        $minShift = -((int) ceil($b['duration'] / 1440) + 1);
        $maxShift = (int) ceil($a['duration'] / 1440) + 1;

        foreach ($a['weekdays'] as $weekdayA) {
            for ($shift = $minShift; $shift <= $maxShift; $shift++) {

                $weekdayB = (
                    (($weekdayA - 1 + $shift) % 7 + 7) % 7
                ) + 1;

                if (!in_array($weekdayB, $b['weekdays'], true)) {
                    continue;
                }

                // Date minimale où les deux programmes
                // peuvent avoir des départs.
                $bAdjustedStart = $bStart->subDays($shift);

                $first = $aStart->gt($bAdjustedStart)
                    ? $aStart
                    : $bAdjustedStart;

                // Date maximale autorisée.
                $last = $aEnd;
                $bAdjustedEnd = $bEnd?->subDays($shift);

                if (
                    $bAdjustedEnd !== null &&
                    ($last === null || $bAdjustedEnd->lt($last))
                ) {
                    $last = $bAdjustedEnd;
                }

                // Premier jour correspondant au weekday.
                $daysToAdd = (
                    $weekdayA - $first->dayOfWeekIso + 7
                ) % 7;

                $dateA = $first->addDays($daysToAdd);

                if ($last !== null && $dateA->gt($last)) {
                    continue;
                }

                $dateB = $dateA->addDays($shift);

                $departureA = CarbonImmutable::parse(
                    $dateA->toDateString() . ' ' . $a['departure_time']
                );

                $departureB = CarbonImmutable::parse(
                    $dateB->toDateString() . ' ' . $b['departure_time']
                );

                $arrivalA = $departureA->addMinutes($a['duration']);
                $arrivalB = $departureB->addMinutes($b['duration']);

                if (
                    $departureA->lt($arrivalB) &&
                    $departureB->lt($arrivalA)
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
