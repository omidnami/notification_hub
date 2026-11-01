<?php

namespace App\Http\Controllers;

use App\Services\Email\GmailAdapter;
use App\Services\Email\MailGatewayException;
use App\Services\Email\SmtpAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class MailGatewayController extends Controller
{
    public function __invoke(Request $request, GmailAdapter $gmail, SmtpAdapter $smtp): JsonResponse
    {
        if (strlen($request->getContent()) > 15 * 1024 * 1024) {
            return response()->json(['ok' => false], 413);
        }

        $input = $request->validate([
            'action' => ['required', 'string', 'in:gmail.exchange,gmail.user,gmail.revoke,gmail.send,smtp.send'],
            'payload' => ['required', 'array'],
        ]);

        $data = $input['payload'];
        try {
            $result = match ($input['action']) {
                'gmail.exchange' => $gmail->exchange(
                    $this->requiredString($data, 'code'), (string) config('services.email_gateway.google_redirect_uri'),
                ),
                'gmail.user' => $gmail->identity($this->requiredString($data, 'access_token')),
                'gmail.revoke' => (function () use ($gmail, $data): array {
                    $gmail->revoke($this->requiredString($data, 'refresh_token'));
                    return [];
                })(),
                'gmail.send' => $this->guardedSend(
                    $data, fn () => $gmail->send(
                        $this->requiredArray($data, 'account'),
                        $this->requiredArray($data, 'message'),
                    ),
                ),
                'smtp.send' => $this->guardedSend(
                    $data, fn () => $smtp->send(
                        $this->requiredArray($data, 'account'),
                        $this->requiredArray($data, 'message'),
                    ),
                ),
            };

            return response()->json(['ok' => true, 'result' => $result]);
        } catch (InvalidArgumentException) {
            return response()->json(['ok' => false], 422);
        } catch (MailGatewayException $e) {
            Log::warning('Email provider rejected gateway operation.', ['action' => $input['action']]);
            return response()->json(
                ['ok' => false],
                $e->retryable ? 429 : ($e->indeterminate ? 502 : 422),
                $e->retryAfter > 0 ? ['Retry-After' => (string) $e->retryAfter] : [],
            );
        } catch (Throwable $e) {
            Log::error('Unexpected email gateway error.', ['action' => $input['action']]);
            return response()->json(['ok' => false], 502);
        }
    }

    private function guardedSend(array $payload, callable $sender): array
    {
        $key = $this->requiredString($payload, 'delivery_key');
        if (!preg_match('/^[A-Za-z0-9_-]{24,128}$/', $key)) {
            throw new InvalidArgumentException('Invalid delivery key.');
        }

        $cacheKey = 'email:gateway:accepted:'.hash('sha256', $key);
        return Cache::lock($cacheKey.':lock', 45)->block(8, function () use ($cacheKey, $sender): array {
            $existing = Cache::get($cacheKey);
            if (is_array($existing)) {
                return $existing;
            }

            $result = $sender();
            Cache::put($cacheKey, $result, now()->addDays(7));

            return $result;
        });
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 8192) {
            throw new InvalidArgumentException('Missing gateway string.');
        }
        return $value;
    }

    private function requiredArray(array $payload, string $key): array
    {
        if (!is_array($payload[$key] ?? null)) {
            throw new InvalidArgumentException('Missing gateway data.');
        }
        return $payload[$key];
    }
}
