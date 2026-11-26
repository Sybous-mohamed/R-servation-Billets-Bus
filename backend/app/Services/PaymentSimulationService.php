<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Ticket;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentSimulationService
{
    public function pay(
        int $reservationId,
        int $userId
    ): array {

        return DB::transaction(function () use (
            $reservationId,
            $userId
        ) {

            // 1. Verifier proprietaire
            $seatId = DB::table('reservations')
                ->where('id', $reservationId)
                ->where('user_id', $userId)
                ->value('bus_seat_id');

            if (!$seatId) {
                abort(404, 'Réservation introuvable.');
            }

            // 2. Verrouiller siege puis reservation
            $seat = DB::table('bus_seats')
                ->where('id', $seatId)
                ->lockForUpdate()
                ->first();

            $reservation = Reservation::query()
                ->whereKey($reservationId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // 3. Gerer un paiement deja effectue
            $existingPayment = Payment::query()
                ->where('reservation_id', $reservationId)
                ->where('status', 'paid')
                ->first();

            if (
                $reservation->status === 'confirmed' &&
                $existingPayment
            ) {
                $existingTicket = Ticket::query()
                    ->where('reservation_id', $reservationId)
                    ->first();

                if (!$existingTicket) {
                    throw ValidationException::withMessages([
                        'payment' => 'Billet introuvable.',
                    ]);
                }

                return $this->formatResult(
                    $reservation,
                    $existingPayment,
                    $existingTicket,
                    true
                );
            }

            if ($existingPayment) {
                throw ValidationException::withMessages([
                    'payment' => 'État de paiement incohérent.',
                ]);
            }

            // 4. Verifier statut et expiration
            if ($reservation->status !== 'pending_payment') {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Cette réservation ne peut pas être payée.',
                ]);
            }

            if (
                !$reservation->hold_expires_at ||
                now()->greaterThanOrEqualTo(
                    $reservation->hold_expires_at
                )
            ) {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Le délai de paiement a expiré.',
                ]);
            }

            // 5. Verifier voyage et siege
            $trip = Trip::query()
                ->whereKey($reservation->trip_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                !in_array(
                    $trip->status,
                    ['scheduled', 'boarding'],
                    true
                ) ||
                !$seat ||
                !$seat->is_active ||
                (int) $seat->bus_id !== (int) $trip->bus_id
            ) {
                throw ValidationException::withMessages([
                    'reservation' => 'Voyage ou siège indisponible.',
                ]);
            }

            $departure = DB::table('trip_stops')
                ->where('id', $reservation->from_trip_stop_id)
                ->where('trip_id', $trip->id)
                ->value('scheduled_departure_at');

            if (!$departure || now()->greaterThanOrEqualTo($departure)) {
                throw ValidationException::withMessages([
                    'reservation' => 'Le départ est déjà passé.',
                ]);
            }

            // 6. Enregistrer le paiement simule
            $payment = Payment::create([
                'reservation_id' => $reservation->id,
                'method' => 'simulation',
                'amount' => $reservation->amount,
                'currency' => $reservation->currency,
                'status' => 'paid',
                'idempotency_key' =>
                    'SIM-RES-' . $reservation->id,
                'paid_at' => now(),
            ]);

            // 7. Generer numero et token du billet
            $ticketNumber = 'TKT-' .
                Str::upper(Str::random(20));

            $qrToken = hash_hmac(
                'sha256',
                $ticketNumber,
                (string) config('app.key')
            );

            $ticket = Ticket::create([
                'reservation_id' => $reservation->id,
                'ticket_number' => $ticketNumber,
                'qr_token_hash' => hash('sha256', $qrToken),
                'status' => 'valid',
                'issued_at' => now(),
            ]);

            // 8. Confirmer la reservation
            $reservation->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'hold_expires_at' => null,
            ]);

            return $this->formatResult(
                $reservation->fresh(),
                $payment,
                $ticket,
                false
            );

        }, 3);
    }

    private function formatResult(
        Reservation $reservation,
        Payment $payment,
        Ticket $ticket,
        bool $alreadyPaid
    ): array {

        return [
            'already_paid' => $alreadyPaid,

            'reservation' => [
                'id' => $reservation->id,
                'reference' => $reservation->reference,
                'status' => $reservation->status,
                'amount' => $reservation->amount,
                'currency' => $reservation->currency,
                'confirmed_at' => $reservation->confirmed_at,
            ],

            'payment' => [
                'id' => $payment->id,
                'method' => $payment->method,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at,
            ],

            'ticket' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'status' => $ticket->status,
                'qr_token' => hash_hmac(
                    'sha256',
                    $ticket->ticket_number,
                    (string) config('app.key')
                ),
            ],
        ];
    }
}

