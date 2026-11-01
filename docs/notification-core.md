# Notification Core — In-App / REST / Outgoing Webhook / IPPanel

The notification database is authoritative. Provider acceptance does NOT establish final delivery.

## Deployment

- New migration **only**: `database/migrations/2026_11_02_030001_create_notification_channel_payloads_and_webhook_audit.php`. Back up your database; apply this version-scoped migration in staging before production. No migrations or seeders were run by this commit.
- Configure `IPPANEL_API_KEY` and an assigned E.164 `IPPANEL_FROM_NUMBER` on the **Iran** server. Never expose credentials to browsers or Git.
- Optional limits: `IPPANEL_PROJECT_DAILY_LIMIT=100`, `IPPANEL_USER_DAILY_LIMIT=300`, `IPPANEL_RECIPIENT_HOURLY_LIMIT=5`.
- Run existing database-queue workers with `critical,urgent,high,normal,low` priorities; jobs for IPPanel, webhooks and existing channels use this queue.
- No new Composer or NPM dependencies.
- The UI `/notifications` requires the existing Laravel web session authentication (a login UI is not included in this module).

## Project REST API

All endpoints require `Authorization: Bearer <project_api_key>`. Project ownership is determined by the existing AuthenticateEmailProject middleware (same keys as Email and Web Push). All methods are tenant-scoped.

| Verb | Endpoint | Description |
| --- | --- | --- |
| POST | /api/v1/notifications | Create notification; requires Idempotency-Key 16–128 chars |
| GET | /api/v1/notifications | Page + filters q, priority, from, to, read, per_page |
| GET | /api/v1/notifications/{id} | Detail and per-channel delivery history |
| PATCH | /api/v1/notifications/{id} | Edit title, body and priority, without modifying already sent provider messages |
| PATCH | /api/v1/notifications/{id}/read | Mark as read |
| DELETE | /api/v1/notifications/{id} | Soft delete; retain delivery history and idempotency |

Example:

```json
{
  "title": "CRM task due",
  "body": "New reminder",
  "priority": 2,
  "channels": ["in_app", "bale", "rubika", "eitaa", "webpush"]
}
```

Omitting channels means `in_app`. Channels `bale`, `rubika`, and `eitaa` only use the project's **owner's verified chat connections**; API callers cannot supply a chat_id. `webpush` targets active devices of that owner. Multiple channels are queued separately and have independent delivery statuses. Missing messenger connections produce a failed delivery row without sending to unknown chats.

For phone delivery, use **one recipient per request** (no blind bulk send) and explicitly assert recipient consent:

SMS text:

```json
{
  "title": "SMS status",
  "channels": ["in_app", "sms"],
  "sms": {
    "type": "text",
    "to": "+989120000000",
    "message": "Service reminder",
    "consent": true
  }
}
```

SMS pattern / OTP: set `sms.type=pattern`, `sms.code=<approved IPPanel pattern>`, and `sms.params={"code":"123456"}` with the single E.164 `sms.to` and `consent=true`.

Voice OTP:

```json
{
  "title": "Voice authentication event",
  "channels": ["voice_otp"],
  "voice_otp": { "to": "+989120000000", "code": "45852", "consent": true }
}
```

The service encrypts phone numbers, pattern parameters and voice OTP in `notification_channel_payloads.payload`; it stores a keyed destination hash to enforce per-recipient throttling. Avoid placing OTP values in notification titles or bodies. Idempotency keys cannot be recycled for different request bodies.

Quota: per user and project rolling 24 hours and per-recipient rolling hour, checked while holding a per-project write lock. IPPanel may respond with 429 if identical messages are submitted within ~40 seconds. Such responses are released for retry with backoff; transport/5xx outcomes that might already have been accepted are marked `needs_review` to avoid duplicate OTP.

## In-App inbox and SSE

User-facing routes require authenticated web sessions and CSRF:

- `GET /notifications`: Vue 3 inbox with search, project/date/priority/read filters, detail, per-channel status, read and soft delete.
- `GET /api/v1/inbox`, `GET /api/v1/inbox/{id}`
- `PATCH /api/v1/inbox/{id}/read`, `DELETE /api/v1/inbox/{id}`
- `GET /api/v1/inbox/stream`: server-sent events for **new** notifications only, event name `notification`, with ID, title and project metadata.

SSE polls the owner's MySQL records every 1.5 seconds and closes after 20 seconds to conserve PHP-FPM workers. EventSource reconnects; `Last-Event-ID` resumes the cursor. Disable reverse-proxy response buffering and set long enough read timeout for this route. Read/delete changes require an inbox refresh; they do not generate separate SSE events in MVP.

## Outgoing Webhooks

Session-authenticated project-owner routes:

- `GET /api/v1/webhooks?project_id=N`
- `POST /api/v1/webhooks` accepts `project_id, url, events[]` and returns a random signing **secret only once**.
- `PATCH /api/v1/webhooks/{id}`: update HTTPS URL, subscribed events or is_active.
- `DELETE /api/v1/webhooks/{id}`: disable; retain history.
- `POST /api/v1/webhooks/{id}/test`: queue one test to that hook.
- `GET /api/v1/webhooks/{id}/deliveries`: delivery history.

Events: `notification.created`, `notification.updated`, `notification.read`, `notification.deleted`, `webhook.test`. Delivery-status events from other channels are **not yet emitted**.

Each webhook POST has:
- `X-NotificationHub-Event: <event_hash>` (dedupe key; receiver should store it)
- `X-NotificationHub-Time: <unix_seconds>`
- `X-NotificationHub-Signature: sha256=<HMAC_SHA256(time + "." + exact_body, secret)>`

Body is structured JSON containing `id, type, occurred_at, project_id, notification_id, data`. Validate timestamp and signature with constant-time comparison at the receiver.

Only HTTPS public DNS names on default port 443 are accepted. Redirects are never followed; no arbitrary IP/custom ports, userinfo or private DNS records. **Mandatory production defense:** outbound firewall egress rules must deny private/reserved IPs and metadata endpoints to prevent DNS rebinding/SSRF. Do not rely on application DNS filtering alone.

Retry temporary errors with backoff up to four attempts and persist the response code, attempts and last error. Receiver should deduplicate the event hash, since network timeouts may occur after successful reception.

## IPPanel credit

Authenticated admin-only `GET /api/v1/admin/ippanel/credit` calls the documented `GET https://edge.ippanel.com/v1/api/payment/credit/mine`. This shows provider-reported balance. The quotas above are local request limits, **not** an authoritative debit or charge ledger.

Sending uses `POST https://edge.ippanel.com/v1/api/send` with official `sending_type=webservice`, `pattern` or `votp`. Voice messages with arbitrary spoken text are not implemented.

## Test status

Feature tests were added for isolation, idempotency, soft-delete, per-recipient SMS limits, IPPanel payload formats, webhooks and SSE auth. Runtime tests, PHP lint, production migration and real IPPanel sends have not been executed here.

Official IPPanel documentation:
- https://ippanelcom.github.io/Edge-Document/fa/docs/send/
- https://ippanelcom.github.io/Edge-Document/fa/docs/send/pattern/
- https://ippanelcom.github.io/Edge-Document/docs/send/votp/
- https://ippanelcom.github.io/Edge-Document/docs/payment/my-credit/
