<?php

namespace App\Services;

use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SeatAvailabilityService
{
    public function getAvailability(
        Trip $trip,
        int $fromId,
        int $toId
    ): array {

        // 1. Verifier le statut du voyage
        if (!in_array(
            $trip->status,
            ['scheduled', 'boarding'],
            true
        )) {
            throw ValidationException::withMessages([
                'trip_id' => 'Ce voyage ne permet pas de reserver.',
            ]);
        }

        // 2. Verifier que les arrêts appartiennent au voyage
        $stops = DB::table('trip_stops')
            ->where('trip_id', $trip->id)
            ->whereIn('id', [$fromId, $toId])
            ->get()
            ->keyBy('id');

        $from = $stops->get($fromId);
        $to = $stops->get($toId);

        if (
            !$from ||
            !$to ||
            $from->stop_order >= $to->stop_order
        ) {
            throw ValidationException::withMessages([
                'segment' => 'Segment invalide pour ce voyage.',
            ]);
        }

        // 3. Verifier que le départ du segment est futur
        if (
            !$from->scheduled_departure_at ||
            Carbon::parse($from->scheduled_departure_at)
                ->lessThanOrEqualTo(now())
        ) {
            throw ValidationException::withMessages([
                'segment' => 'Le départ de cet arrêt est déjà passé.',
            ]);
        }

        // 4. Chercher le tarif exact du segment
        $fare = DB::table('route_fares')
            ->where('route_id', $trip->route_id)
            ->where('from_route_stop_id', $from->route_stop_id)
            ->where('to_route_stop_id', $to->route_stop_id)
            ->first();

        if (!$fare) {
            throw ValidationException::withMessages([
                'segment' => 'Aucun tarif pour ce segment.',
            ]);
        }

        // 5. Identifier les sièges occupés sur ce segment
        $occupiedIds = DB::table('reservations')
            ->where('trip_id', $trip->id)
            ->where('from_stop_order', '<', $to->stop_order)
            ->where('to_stop_order', '>', $from->stop_order)
            ->where(function ($query) {
                $query->whereIn('status', [
                    'confirmed',
                    'cancellation_requested',
                    'completed',
                    'no_show',
                ])
                ->orWhere(function ($hold) {
                    $hold->where('status', 'pending_payment')
                        ->where('hold_expires_at', '>', now());
                });
            })
            ->distinct()
            ->pluck('bus_seat_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // 6. Charger les sièges actifs du bus
        $seats = DB::table('bus_seats')
            ->where('bus_id', $trip->bus_id)
            ->where('is_active', 1)
            ->get()
            ->sortBy(fn ($seat) => (int) $seat->seat_number)
            ->values()
            ->map(function ($seat) use ($occupiedIds) {

                return [
                    'id' => (int) $seat->id,
                    'seat_number' => $seat->seat_number,
                    'seat_type' => $seat->seat_type,
                    'is_available' => !in_array(
                        (int) $seat->id,
                        $occupiedIds,
                        true
                    ),
                ];
            });

        return [
            'trip_id' => $trip->id,
            'from_trip_stop_id' => $fromId,
            'to_trip_stop_id' => $toId,
            'from_stop_order' => $from->stop_order,
            'to_stop_order' => $to->stop_order,
            'fare' => [
                'amount' => $fare->amount,
                'currency' => $fare->currency,
            ],
            'total_seats' => $seats->count(),
            'available_count' => $seats
                ->where('is_available', true)
                ->count(),
            'occupied_count' => $seats
                ->where('is_available', false)
                ->count(),
            'seats' => $seats->all(),
        ];
    }
}
