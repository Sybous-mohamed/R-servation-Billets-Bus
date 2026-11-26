<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireReservationHolds extends Command
{
    protected $signature = 'reservations:expire-holds';

    protected $description =
        'Expire les reservations dont le delai de paiement est depasse';

    public function handle(): int
    {
        $now = now();

        $expiredCount = DB::table('reservations')
            ->where('status', 'pending_payment')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', $now)
            ->update([
                'status' => 'expired',
                'updated_at' => $now,
            ]);

        $this->info(
            "Reservations expirees : {$expiredCount}"
        );

        return self::SUCCESS;
    }
}
