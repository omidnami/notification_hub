<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBotApi;
use App\Services\Telegram\TelegramException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramSendGatewayController extends Controller
{
    public function __invoke(Request $request, TelegramBotApi $bot): JsonResponse
    {
        $input = $request->validate([
            'chat_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/'],
            'text' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $result = $bot->sendMessage($input['chat_id'], $input['text']);
        } catch (TelegramException $e) {
            $status = $e->httpStatus === 429 ? 429
                : ($e->httpStatus === 403 ? 403
                : ($e->indeterminate ? 502 : 422));

            return response()->json([
                'ok' => false,
                'error_code' => $e->httpStatus,
                'indeterminate' => $e->indeterminate,
                'retry_after' => $e->retryAfter,
            ], $status, $e->retryAfter > 0 ? ['Retry-After' => (string) $e->retryAfter] : []);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'indeterminate' => true], 502);
        }

        return response()->json(['ok' => true, 'result' => [
            'message_id' => $result['message_id'],
        ]]);
    }
}
