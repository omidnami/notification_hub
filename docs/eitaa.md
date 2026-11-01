# Eitaa channel (official EitaaYar App API)

**Provider:** EitaaYar App API, https://eitaayar.ir/api/app/sendMessage.
No unofficial account automation, bot webhook, or extra database table.

## Configure

Create an application in EitaaYar and configure its HTTPS web app URL.

- `EITAA_APP_TOKEN`: the application token, kept server-side.
- `EITAA_APP_URL`: link to the registered Eitaa app or mini-app (not assumed to be a generic web URL).
- `EITAA_MESSAGE_BUDGET`: default 50,000 accepted messages across this installation. Adjust to available credit. The counter is local and **not** the provider's authoritative balance.

## Connect users (backend only)

1. Logged-in Hub user POSTs `/api/v1/channels/eitaa/connect` and receives a random 16-character pairing code valid for 10 minutes.
2. User opens the registered app in Eitaa. A *minimal app frontend still needs to be supplied* to collect the code and `window.Eitaa.WebApp.initData`. This repository intentionally does not build a frontend at this stage.
3. App sends `POST /api/v1/channels/eitaa/complete` with JSON fields `pairing_code` and `init_data`. This endpoint uses cryptographically verified Eitaa initData (HMAC-SHA256 from the official Eitaa PHP validation example), freshness limit of five minutes, and checks that `user.allows_write_to_pm === true`.
4. A one-use pairing code is consumed after DB commit. The resulting user ID is stored as `connections.external_id`, under provider `eitaa`.

GET `/api/v1/channels/eitaa/connection` checks account status; DELETE disconnects. The app must request write permission if not already granted.

## Sending and credits

`app(\App\Services\EitaaService::class)->queueDelivery($notificationId, $connectionId)` records per-channel delivery and enqueues SendEitaaNotification by priority. The job uses the **official** EitaaYar sendMessage endpoint and expects `{"ok":true,"result":"success"}`.

Each accepted send increments the local counter in `options` with key `eitaa.sent_count`. A shared cache lock serializes the budget check and increments; this requires a **shared atomic cache** across workers/nodes. The local counter does not account for messages sent outside Notification Hub. Provider credit/top-ups must be reconciled manually. Failed requests do not increment this counter.

## Limitations

- EitaaYar documents an initial allocation of 50,000 free messages **per new app**, not per month.
- Private delivery is available only after user permission.
- A webhook/event feed for this app integration is not documented; none is implemented.
- Official app startup currently works in Android and Windows desktop clients per Eitaa documentation.
- The public app linking view and general Notification API are not implemented yet.

Sources:
- https://github.com/eitaa/Documentation/blob/main/docs/Develop/SendMassage.md
- https://github.com/eitaa/Documentation/blob/main/docs/Develop/AuthorizationViaHash.md
- https://github.com/eitaa/initdata-validation-examples/blob/main/PHP/index.php
