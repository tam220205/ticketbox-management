<?php

namespace App\Http\Controllers;

use App\Services\SePayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SePayWebhookController extends Controller
{
    public function __invoke(Request $request, SePayPaymentService $payments): JsonResponse
    {
        if (! config('services.sepay.enabled')) {
            return response()->json(['success' => false, 'message' => 'SePay integration is disabled.'], 503);
        }

        $expected = trim((string) config('services.sepay.webhook_api_key'));
        $authorization = trim((string) $request->header('Authorization'));
        $provided = preg_match('/^Apikey\s+(.+)$/i', $authorization, $matches)
            ? trim($matches[1])
            : trim((string) $request->header('X-SePay-Api-Key'));

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $payload = $request->all();
        if (empty($payload['id']) && empty($payload['referenceCode'])) {
            return response()->json(['success' => false, 'message' => 'Transaction ID is required.'], 422);
        }

        $result = $payments->receive($payload);

        return response()->json(['success' => true, 'result' => $result['status']]);
    }
}
