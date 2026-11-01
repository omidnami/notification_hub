<?php

namespace App\Http\Controllers;

use App\Services\Push\PushDispatchService;
use App\Services\Push\PushSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PushSubscriptionController extends Controller
{
    public function configuration(): JsonResponse
    {
        $public = (string) config('services.webpush.public_key');

        return response()->json(['public_key' => $public, 'configured' => $public !== '']);
    }

    public function index(Request $request): JsonResponse
    {
        $devices = DB::table('push_subscriptions')->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get(['id', 'device_id', 'device_name', 'browser', 'platform', 'status', 'last_success_at', 'created_at']);

        return response()->json(['devices' => $devices]);
    }

    public function store(Request $request, PushSubscriptionService $subscriptions): JsonResponse
    {
        $input = $request->validate([
            'device_id' => ['required', 'uuid'],
            'endpoint' => ['required', 'url:https', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:256'],
            'keys.auth' => ['required', 'string', 'max:128'],
            'content_encoding' => ['sometimes', 'string', 'in:aes128gcm,aesgcm'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'browser' => ['nullable', 'string', 'max:50'],
            'platform' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $id = $subscriptions->register((int) $request->user()->id, $input);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['device_id' => $id, 'status' => 'active'], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table('push_subscriptions')
            ->where('id', $id)->where('user_id', $request->user()->id)->delete();

        return $deleted
            ? response()->json(['deleted' => true])
            : response()->json(['message' => 'Device not found.'], 404);
    }

    public function test(
        Request $request,
        PushSubscriptionService $subscriptions,
        PushDispatchService $dispatch,
    ): JsonResponse {
        $input = $request->validate(['device_id' => ['nullable', 'integer', 'min:1']]);
        $userId = (int) $request->user()->id;
        $selected = [];

        if (isset($input['device_id'])) {
            try {
                $device = $subscriptions->ownedDevice($userId, $input['device_id']);
            } catch (InvalidArgumentException) {
                return response()->json(['message' => 'Device not found.'], 404);
            }
            if ($device->status !== 'active') {
                return response()->json(['message' => 'Device is not active.'], 422);
            }
            $selected = [$device->id];
        } else {
            $selected = DB::table('push_subscriptions')
                ->where('user_id', $userId)->where('status', 'active')->pluck('id')->all();
        }

        if ($selected === []) {
            return response()->json(['message' => 'No active push device.'], 422);
        }

        $id = DB::table('notifications')->insertGetId([
            'user_id' => $userId, 'title' => 'آزمایش اعلان Notification Hub',
            'body' => 'اعلان آزمایشی با موفقیت به صف ارسال اضافه شد.',
            'priority' => 4, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dispatch->queueNotification((int) $id, $selected);

        return response()->json(['notification_id' => $id, 'queued_devices' => count($selected)], 202);
    }
}
