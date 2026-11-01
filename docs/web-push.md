# Web Push — Notification Hub

**Status:** Backend, Vue 3 activation page and Service Worker implemented; real browser delivery not yet tested.

## Dependencies and installation

- PHP >= 8.3, openssl, curl, mbstring (plus Composer dependencies).
- `composer update minishlink/web-push --with-all-dependencies` (the repository currently has no composer.lock or vendor/).
- `npm install --ignore-scripts && npm run build` (Vue 3 and @vitejs/plugin-vue added to package.json).
- Apply the **single new** migration `database/migrations/2026_11_02_020001_create_web_push_subscriptions.php` after backing up the database; do not run migrations against production until reviewed.
- Run `php artisan webpush:vapid:generate` **once**; securely place the generated keys in environment configuration. **Never commit a VAPID private key.** Keep the same key pair permanently for existing subscriptions.
- Configure:
  - `WEBPUSH_VAPID_SUBJECT=mailto:admin@yourdomain.tld` (must be a valid mailto or HTTPS URL)
  - `WEBPUSH_VAPID_PUBLIC_KEY=<public>`
  - `WEBPUSH_VAPID_PRIVATE_KEY=<private>`
  - `APP_URL=https://yourdomain.tld`
- Run the queue worker with `--queue=critical,urgent,high,normal,low`. This project uses Laravel database queues; set QUEUE_CONNECTION=database.

## Device activation

- The UI at `GET /settings/notifications` requires an existing authenticated Hub web session (Hub login/registration UI is not implemented yet).
- The button **«فعال‌سازی اعلان‌ها» is always displayed**. OS/browser detection, PWA detection, permission checks, and tailored compatibility guidance happen **only when clicked**.
- Safari iOS/iPadOS Web Push requires a Home Screen web app on iOS/iPadOS 16.4+; the PWA manifest and PNG icons are included. User must open the installed Home Screen app, click the button, and grant permission.
- Service Worker at `/push-sw.js` displays a visible notification on each push and only opens same-origin URLs on click.
- Authenticated user APIs (web session + CSRF):
  - GET `/api/v1/push/config`: public VAPID key
  - GET `/api/v1/push/devices`: list own devices (never returns endpoint or encryption keys)
  - POST `/api/v1/push/devices`: register browser PushSubscription JSON and UUID device ID
  - DELETE `/api/v1/push/devices/{id}`: remove an owned device
  - POST `/api/v1/push/test`: queue test notification for one owned `device_id` or all own active devices.
- Up to 20 devices per user. Endpoints and browser encryption keys are encrypted at rest, with a SHA-256 endpoint hash for uniqueness. An endpoint cannot be claimed by another Hub user.

## Project notification API

Only project-scoped bearer keys are accepted (uses existing AuthenticateEmailProject middleware). Other user's devices cannot be selected.

`POST /api/v1/push/notifications`

Headers: `Authorization: Bearer <project_key>`, `Idempotency-Key: <unique 16–128 character key>`.

JSON example:
```json
{
  "title": "New task",
  "body": "A reminder from your CRM",
  "url": "/settings/notifications",
  "icon": "/push-icon-192.png",
  "priority": 4,
  "device_ids": [3, 5]
}
```
Omit `device_ids` to target **all active devices of the project's owner**. A project can never supply another user's endpoint or arbitrary user ID. Paths for icon and URL must be same-origin paths.

`GET /api/v1/push/notifications/{id}` returns per-device delivery states and attempts. `sent` means accepted by browser vendor push service — **not confirmed shown on the end device**.

Queue adapter: `app(\App\Services\Push\PushDispatchService::class)->queueNotification($notificationId, $selectedDeviceIdsOrNull)` for already created notifications. It inserts one delivery per device, schedules by the parent notification's scheduled_at, and selects one of five priority queues. HTTP 404/410 marks subscriptions `expired` and stops delivery; 429/5xx are retried with backoff; uncertain outcomes require review rather than blind duplicate sends.

## Germany outbound gateway (only when Iran egress is blocked)

Iran:
```dotenv
WEBPUSH_USE_GATEWAY=true
WEBPUSH_GATEWAY_URL=https://<germany-host>
WEBPUSH_GATEWAY_SECRET=<shared-random-32+-character-secret>
WEBPUSH_GATEWAY_ENABLED=false
```

Germany: deploy the same code with no Iran database access; set `WEBPUSH_GATEWAY_ENABLED=true`, `WEBPUSH_USE_GATEWAY=false`, matching gateway secret, VAPID subject and **same VAPID key pair**. Make `POST /internal/web-push` accessible only from the Iran server over HTTPS (firewall allowlist). This endpoint uses HMAC-SHA256 over timestamp, nonce, and exact JSON body, with a short replay window and cache-based nonce uniqueness.

The Germany gateway has *no* notification database or users; only performs a signed, narrowly scoped outbound Web Push request. Enforce network firewall deny-rules for private/reserved IPs and metadata services to mitigate DNS rebinding/SSRF; software DNS checks are only defense in depth.

## Caveats

- A worker must be running, the VAPID keys must match the subscription, and the host must use HTTPS (localhost works during development).
- Web Push uses browser vendor push infrastructure, not OneSignal/Push.co. No commercial per-message fee.
- Push endpoint reachability varies by network; the gateway is optional and configured explicitly.
- Test on Android, iOS Home Screen, Windows, Linux and different browsers before declaring production readiness.
- Do not interpret HTTP 2xx from Push Service as proof of final device delivery.

References: https://github.com/web-push-libs/web-push-php and https://developer.apple.com/documentation/usernotifications/sending-web-push-notifications-in-web-apps-and-browsers
