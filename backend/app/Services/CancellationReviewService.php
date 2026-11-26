<?php

namespace App\Services;

use App\Models\CancellationRequest;
use App\Models\Reservation;
use App\Models\Payment;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancellationReviewService
{
    public function review(
        int $requestId,
        int $adminId,
        string $decision
    ): array {
        return DB::transaction(function () use (
            $requestId,
            $adminId,
            $decision
        ) {
            // 1. Identifier le siege concerne
            $requestInfo = CancellationRequest::findOrFail(
                $requestId
            );

            $seatId = Reservation::whereKey(
                $requestInfo->reservation_id
            )->value('bus_seat_id');

            if (!$seatId) {
                abort(404);
            }

            // 2. Verrouiller le siege, puis la reservation
            $seat = DB::table('bus_seats')
                ->where('id', $seatId)
                ->lockForUpdate()
                ->first();

            if (!$seat) {
                abort(404);
            }

            $reservation = Reservation::query()
                ->whereKey($requestInfo->reservation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $cancellation = CancellationRequest::query()
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $cancellation->reservation_id !==
                (int) $reservation->id
            ) {
                throw ValidationException::withMessages([
                    'request' => 'Demande incoherente.',
                ]);
            }

            // 3. Eviter le double traitement
            if ($cancellation->status !== 'pending') {
                if ($cancellation->status === $decision) {
                    return $this->result(
                        $cancellation,
                        $reservation,
                        true
                    );
                }

                throw ValidationException::withMessages([
                    'decision' =>
                        'Cette demande a deja ete traitee.',
                ]);
            }

            if (
                $reservation->status !==
                'cancellation_requested'
            ) {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Statut de reservation incoherent.',
                ]);
            }

            // 4. APPROBATION
            if ($decision === 'approved') {

                $trip = DB::table('trips')
                    ->where('id', $reservation->trip_id)
                    ->lockForUpdate()
                    ->first();

                $departure = DB::table('trip_stops')
                    ->where('id', $reservation->from_trip_stop_id)
                    ->where('trip_id', $reservation->trip_id)
                    ->value('scheduled_departure_at');

                if (
                    !$trip ||
                    !in_array(
                        $trip->status,
                        ['scheduled', 'boarding'],
                        true
                    ) ||
                    !$departure ||
                    Carbon::parse($departure)->lessThanOrEqualTo(now())
                ) {
                    throw ValidationException::withMessages([
                        'reservation' =>
                            'Annulation impossible apres le depart.',
                    ]);
                }

                // Paiement simule uniquement
                $payments = Payment::query()
                    ->where('reservation_id', $reservation->id)
                    ->lockForUpdate()
                    ->get();

                if (
                    $payments->count() !== 1 ||
                    $payments->first()->status !== 'paid' ||
                    $payments->first()->method !== 'simulation'
                ) {
                    throw ValidationException::withMessages([
                        'payment' =>
                            'Paiement incompatible avec le remboursement simule.',
                    ]);
                }

                // Billet non utilise
                $tickets = Ticket::query()
                    ->where('reservation_id', $reservation->id)
                    ->lockForUpdate()
                    ->get();

                if (
                    $tickets->count() !== 1 ||
                    $tickets->first()->status !== 'valid' ||
                    $tickets->first()->scanned_at !== null
                ) {
                    throw ValidationException::withMessages([
                        'ticket' =>
                            'Billet invalide ou deja utilise.',
                    ]);
                }

                $payments->first()->update([
                    'status' => 'refunded',
                    'refunded_at' => now(),
                ]);

                $tickets->first()->update([
                    'status' => 'void',
                ]);

                $reservation->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

            } else {
                // 5. REFUS : restaurer la reservation
                $reservation->update([
                    'status' => 'confirmed',
                ]);
            }

            // 6. Enregistrer la decision Admin
            $cancellation->update([
                'status' => $decision,
                'reviewed_by_user_id' => $adminId,
                'reviewed_at' => now(),
            ]);

            return $this->result(
                $cancellation,
                $reservation,
                false
            );

        }, 3);
    }

    private function result(
        CancellationRequest $cancellation,
        Reservation $reservation,
        bool $alreadyReviewed
    ): array {
        return [
            'cancellation_request_id' => $cancellation->id,
            'request_status' => $cancellation->status,
            'reservation_id' => $reservation->id,
            'reservation_status' => $reservation->status,

            'payment_status' => DB::table('payments')
                ->where('reservation_id', $reservation->id)
                ->value('status'),

            'ticket_status' => DB::table('tickets')
                ->where('reservation_id', $reservation->id)
                ->value('status'),

            'already_reviewed' => $alreadyReviewed,
        ];
    }
}
