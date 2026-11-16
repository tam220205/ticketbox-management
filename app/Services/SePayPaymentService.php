<?php

namespace App\Services;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SePayPaymentService
{
    /** @return array{status: string, booking_id: int|null} */
    public function receive(array $payload): array
    {
        $transactionId = trim((string) ($payload['id'] ?? $payload['referenceCode'] ?? ''));
        $amount = (int) ($payload['transferAmount'] ?? $payload['amount'] ?? 0);
        $direction = strtolower(trim((string) ($payload['transferType'] ?? $payload['type'] ?? '')));
        $receivedAccount = preg_replace('/\D+/', '', (string) ($payload['accountNumber'] ?? ''));
        $expectedAccount = preg_replace('/\D+/', '', (string) config('services.ticket_payment.account_no'));
        $description = trim(implode(' ', array_filter([
            (string) ($payload['content'] ?? ''),
            (string) ($payload['code'] ?? ''),
            (string) ($payload['description'] ?? ''),
        ])));
        $bookingCode = $this->bookingCodeFrom($description);

        if ($transactionId === '') {
            return ['status' => 'missing_transaction_id', 'booking_id' => null];
        }

        try {
            $result = DB::transaction(function () use ($payload, $transactionId, $amount, $direction, $bookingCode, $receivedAccount, $expectedAccount): array {
                $existing = DB::table('payment_transactions')
                    ->where('provider', 'sepay')
                    ->where('provider_transaction_id', $transactionId)
                    ->first();
                if ($existing) {
                    return ['status' => 'duplicate_webhook', 'booking_id' => $existing->booking_id];
                }

                $booking = $bookingCode ? Booking::where('booking_code', $bookingCode)->lockForUpdate()->first() : null;
                $status = 'unmatched';

                if (! in_array($direction, ['in', 'income', 'credit'], true)) {
                    $status = 'not_incoming';
                } elseif ($expectedAccount === '' || $receivedAccount === '' || ! hash_equals($expectedAccount, $receivedAccount)) {
                    $status = 'wrong_account';
                } elseif (! $booking) {
                    $status = 'unmatched';
                } elseif ($amount !== (int) $booking->total_price) {
                    $status = 'amount_mismatch';
                } elseif ($booking->status !== 'pending') {
                    $status = $booking->status === 'confirmed' ? 'duplicate_payment' : 'inactive_booking';
                } elseif ($booking->payment_expires_at && $booking->payment_expires_at->isPast()) {
                    $status = 'late_payment';
                } else {
                    $status = 'matched';
                }

                $safePayload = array_intersect_key($payload, array_flip([
                    'id', 'gateway', 'transactionDate', 'code', 'content', 'transferType', 'transferAmount', 'referenceCode',
                ]));
                DB::table('payment_transactions')->insert([
                    'provider' => 'sepay',
                    'provider_transaction_id' => $transactionId,
                    'booking_id' => $booking?->id,
                    'amount' => max(0, $amount),
                    'status' => $status,
                    'payload' => json_encode($safePayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($status === 'matched' && $booking) {
                    $booking->update(['status' => 'confirmed', 'paid_at' => now()]);
                }

                return ['status' => $status, 'booking_id' => $booking?->id];
            });
        } catch (QueryException $exception) {
            $existing = DB::table('payment_transactions')
                ->where('provider', 'sepay')
                ->where('provider_transaction_id', $transactionId)
                ->first();
            if (! $existing) {
                throw $exception;
            }
            $result = ['status' => 'duplicate_webhook', 'booking_id' => $existing->booking_id];
        }

        if ($result['status'] === 'matched' && $result['booking_id']) {
            $booking = Booking::find($result['booking_id']);
            if ($booking) {
                try {
                    Mail::to($booking->buyer_email)->queue(new BookingConfirmationMail($booking));
                } catch (\Throwable $exception) {
                    Log::warning('Could not queue payment confirmation email.', [
                        'booking_id' => $booking->id,
                        'exception' => $exception::class,
                    ]);
                }
            }
        } elseif (! in_array($result['status'], ['duplicate_webhook'], true)) {
            Log::notice('SePay transaction was not applied to a booking.', [
                'provider_transaction_id' => $transactionId,
                'status' => $result['status'],
                'booking_id' => $result['booking_id'],
            ]);
        }

        return $result;
    }

    private function bookingCodeFrom(string $description): ?string
    {
        return preg_match('/(?<![A-Z0-9])(TBX-[A-Z0-9]{8})(?![A-Z0-9])/i', $description, $matches)
            ? strtoupper($matches[1])
            : null;
    }
}
