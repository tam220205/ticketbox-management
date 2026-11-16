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
        if (config('services.ticket_payment_demo.enabled') && app()->environment(['local', 'testing'])) {
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

            return response()->json([
                'message' => 'Thanh toán mô phỏng thành công. Không có giao dịch ngân hàng thật.',
                'status' => $booking->status,
                'redirect' => route('bookings.ticket', $booking->id),
            ]);
        }

        if (! config('services.sepay.enabled')) {
            return response()->json([
                'message' => 'Thanh toán chưa khả dụng: chưa cấu hình xác nhận giao dịch SePay.',
            ], 503);
        }

        if ($request->validated('payment_method') !== 'vietqr') {
            return response()->json(['message' => 'Hiện chỉ hỗ trợ chuyển khoản VietQR tự động.'], 422);
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
}
