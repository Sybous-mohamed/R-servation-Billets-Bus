<?php

namespace App\Services;

use App\Models\CancellationRequest;
use App\Models\Reservation;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancellationRequestService
{
    public function create(
        int $reservationId,
        int $userId,
        ?string $reason = null
    ): array {
        return DB::transaction(function () use (
            $reservationId,
            $userId,
            $reason
        ) {

            // 1. Verrouiller la reservation du client
            $reservation = Reservation::query()
                ->whereKey($reservationId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Eviter les demandes repetees
            if ($reservation->status === 'cancellation_requested') {

                $existing = CancellationRequest::query()
                    ->where('reservation_id', $reservation->id)
                    ->where('status', 'pending')
                    ->latest('id')
                    ->first();

                if ($existing) {
                    return [
                        'request' => $existing,
                        'already_requested' => true,
                    ];
                }

                throw ValidationException::withMessages([
                    'reservation' =>
                        'Etat de demande d annulation incoherent.',
                ]);
            }

            // 3. Seules les reservations confirmees
            // peuvent etre annulees
            if ($reservation->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Cette reservation ne peut pas etre annulee.',
                ]);
            }

            // 4. Verifier le voyage
            $trip = Trip::findOrFail($reservation->trip_id);

            if (!in_array(
                $trip->status,
                ['scheduled', 'boarding'],
                true
            )) {
                throw ValidationException::withMessages([
                    'trip' => 'Le voyage a deja commence ou est termine.',
                ]);
            }

            // 5. Verifier la date de depart du client
            $departure = DB::table('trip_stops')
                ->where('id', $reservation->from_trip_stop_id)
                ->where('trip_id', $trip->id)
                ->value('scheduled_departure_at');

            if (
                !$departure ||
                Carbon::parse($departure)->lessThanOrEqualTo(now())
            ) {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Annulation impossible apres le depart.',
                ]);
            }

            // 6. Verifier le paiement
            $hasPayment = DB::table('payments')
                ->where('reservation_id', $reservation->id)
                ->where('status', 'paid')
                ->exists();

            if (!$hasPayment) {
                throw ValidationException::withMessages([
                    'payment' =>
                        'Aucun paiement confirme pour cette reservation.',
                ]);
            }

            // 7. Verifier le billet
            $ticket = DB::table('tickets')
                ->where('reservation_id', $reservation->id)
                ->where('status', 'valid')
                ->whereNull('scanned_at')
                ->first();

            if (!$ticket) {
                throw ValidationException::withMessages([
                    'ticket' =>
                        'Billet invalide ou deja utilise.',
                ]);
            }

            // 8. Protection contre demande en double
            $alreadyPending = CancellationRequest::query()
                ->where('reservation_id', $reservation->id)
                ->where('status', 'pending')
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Une demande d annulation existe deja.',
                ]);
            }

            // 9. Creer la demande
            $cancellation = CancellationRequest::create([
                'reservation_id' => $reservation->id,
                'requested_by_user_id' => $userId,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            // 10. Mettre a jour le statut
            $reservation->update([
                'status' => 'cancellation_requested',
            ]);

            return [
                'request' => $cancellation,
                'already_requested' => false,
            ];

        }, 3);
    }
}
