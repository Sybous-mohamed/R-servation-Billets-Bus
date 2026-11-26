<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationCreationService
{
    public function __construct(
        private SeatAvailabilityService $availability
    ) {}

    public function create(array $data, int $userId): Reservation
    {
        return DB::transaction(function () use ($data, $userId) {

            // 1. Verrouiller le siège pour éviter
            // deux réservations simultanées.
            $seat = DB::table('bus_seats')
                ->where('id', $data['bus_seat_id'])
                ->lockForUpdate()
                ->first();

            if (!$seat || !$seat->is_active) {
                throw ValidationException::withMessages([
                    'bus_seat_id' => 'Siège indisponible.',
                ]);
            }

            // 2. Récupérer le voyage
            $trip = Trip::findOrFail($data['trip_id']);

            if ((int) $seat->bus_id !== (int) $trip->bus_id) {
                throw ValidationException::withMessages([
                    'bus_seat_id' => 'Ce siège ne fait pas partie du bus.',
                ]);
            }

            // 3. Recalculer disponibilité et tarif
            // à l'intérieur de la transaction.
            $result = $this->availability->getAvailability(
                $trip,
                (int) $data['from_trip_stop_id'],
                (int) $data['to_trip_stop_id']
            );

            // 4. Vérifier le siège demandé
            $selectedSeat = collect($result['seats'])
                ->firstWhere('id', (int) $seat->id);

            if (!$selectedSeat || !$selectedSeat['is_available']) {
                throw ValidationException::withMessages([
                    'bus_seat_id' =>
                        'Ce siège est déjà réservé sur ce segment.',
                ]);
            }

            // 5. Créer réservation temporaire
            $reservation = Reservation::create([
                'reference' => 'RES-' . Str::upper(Str::random(16)),
                'user_id' => $userId,
                'trip_id' => $trip->id,
                'bus_seat_id' => $seat->id,
                'from_trip_stop_id' => $result['from_trip_stop_id'],
                'to_trip_stop_id' => $result['to_trip_stop_id'],
                'from_stop_order' => $result['from_stop_order'],
                'to_stop_order' => $result['to_stop_order'],
                'passenger_name' => $data['passenger_name'],
                'passenger_phone' => $data['passenger_phone'] ?? null,
                'amount' => $result['fare']['amount'],
                'currency' => $result['fare']['currency'],
                'status' => 'pending_payment',
                'hold_expires_at' => now()->addMinutes(15),
            ]);

            return $reservation;
        }, 3);
    }
}
