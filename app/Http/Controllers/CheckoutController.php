<?php

namespace App\Http\Controllers;

use App\Exceptions\SeatHoldException;
use App\Http\Requests\CheckoutRequest;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        if (config('services.sepay.enabled')) {
            if ($request->validated('payment_method') !== 'vietqr') {
                return response()->json(['message' => 'Khi bật thanh toán tự động, hiện chỉ hỗ trợ chuyển khoản VietQR.'], 422);
            }
            if (trim((string) config('services.sepay.webhook_api_key')) === '') {
                return response()->json(['message' => 'Chưa cấu hình khóa bảo mật webhook SePay.'], 503);
            }

            try {
                $booking = $checkout->startBankTransfer(
                    $request->user(),
                    $request->validated('delivery_email'),
                    $request->validated('delivery_name'),
                    $request->validated('delivery_phone'),
                    $request->input('discount_code'),
                    $request->input('seat_ids'),
                );
            } catch (SeatHoldException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return response()->json([
                'message' => $booking->status === 'confirmed'
                    ? 'Đơn hàng không phát sinh số tiền phải trả. Vé điện tử đã được phát hành.'
                    : 'Đơn đã tạo. Quét mã VietQR và chuyển đúng số tiền; vé sẽ được xác nhận tự động khi ngân hàng báo tiền vào.',
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'total_price' => $booking->total_price,
                'status' => $booking->status,
                'payment_expires_at' => $booking->payment_expires_at?->toIso8601String(),
                'history_url' => route('bookings.history'),
            ]);
        }

        try {
            $booking = $checkout->checkout(
                $request->user(),
                $request->validated('payment_method'),
                $request->validated('delivery_email'),
                $request->validated('delivery_name'),
                $request->validated('delivery_phone'),
                $request->input('discount_code'),
                $request->input('seat_ids'),
            );
        } catch (SeatHoldException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $ticketUrl = route('bookings.ticket', $booking->id);

        return response()->json([
            'message' => 'Thanh toán thành công! Vé điện tử đã được phát hành.',
            'booking_code' => $booking->booking_code,
            'total_price' => $booking->total_price,
            'qr_data_uri' => 'data:image/svg+xml;base64,'.base64_encode((string) \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
                ->size(250)
                ->margin(2)
                ->generate('TICKETBOX_AUTH_PASS_'.$booking->booking_code)),
            'redirect' => $ticketUrl,
            'ticket_url' => $ticketUrl,
            'history_url' => route('bookings.history'),
        ]);
    }
}
