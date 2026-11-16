<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\ShowtimeSeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireUnpaidBookings extends Command
{
    protected $signature = 'payments:expire-unpaid';

    protected $description = 'Cancel expired unpaid bookings and release their reserved seats';

    public function handle(): int
    {
        $expiredIds = Booking::where('status', 'pending')
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');

        $count = 0;
        foreach ($expiredIds as $id) {
            $didExpire = DB::transaction(function () use ($id): bool {
                $booking = Booking::whereKey($id)->lockForUpdate()->first();
                if (! $booking || $booking->status !== 'pending' || ! $booking->payment_expires_at?->isPast()) {
                    return false;
                }

                $seatIds = $booking->items()->pluck('showtime_seat_id');
                ShowtimeSeat::whereIn('id', $seatIds)
                    ->where('status', 'booked')
                    ->update([
                        'status' => 'available',
                        'held_by_user_id' => null,
                        'held_until' => null,
                        'price_override' => null,
                    ]);

                if ($booking->discount_id) {
                    DB::table('discounts')->where('id', $booking->discount_id)
                        ->where('used_count', '>', 0)->decrement('used_count');
                }

                $booking->update(['status' => 'cancelled']);

                return true;
            });

            $count += $didExpire ? 1 : 0;
        }

        $this->info("Expired {$count} unpaid bookings.");

        return self::SUCCESS;
    }
}
