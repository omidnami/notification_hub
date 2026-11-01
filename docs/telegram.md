# Telegram Channel — Central Bot (Iran / Germany)

Backend-only Telegram channel, using the **official Telegram Bot API** and an authenticated HTTPS bridge. No Telegram personal-account sessions and no paid third-party intermediary.

## Deployment topology

```text
Iran (role=core): Hub UI / DB / project APIs / Queue
          | HTTPS HMAC for sendMessage
          v
Germany (role=worker): official Telegram Bot API
          ^   |
          |   | HTTPS HMAC for incoming updates
     Telegram  v
       Iran /internal/telegram/events
```

- A **single central bot** is created with Telegram **@BotFather**.
- The Telegram bot token is **only on Germany**; the Iran server stores users, chat IDs, connections, notifications, and all delivery history.
- Germany needs no connection to Iran's MySQL and must **not** have Iran's application database credentials. Set a **separate APP_KEY** there and explicitly use `SESSION_DRIVER=file` and `CACHE_STORE=file` (or its own Redis); the Laravel skeleton defaults to database-backed session/cache.
- Both servers should expose only the endpoints needed for their respective roles and communicate over HTTPS. Firewall allowlist Germany↔Iran source IPs in addition to HMAC signatures; restrict public Germany access to `/hooks/telegram` where feasible, and the signed `/internal/telegram/send` only to Iran.
- For multiple instances, **shared atomic cache/locks** are essential on each side for one-time tokens, nonces, rate limiting and delivery locks. On a single server Laravel file locks may be used if supported by the cache store. Set `CACHE_STORE=file` or Redis on Germany (the default `database` cache would require a database there).
- The existing 5-priority database queue runs in Iran: `critical,urgent,high,normal,low`.

## Environment

Iran (user/account/DB/queue core):

```dotenv
TELEGRAM_ROLE=core
TELEGRAM_BOT_USERNAME=YourCentralBot
TELEGRAM_GATEWAY_URL=https://your-germany-server.example
TELEGRAM_GATEWAY_SECRET=<same-random-secret-as-Germany>
# Leave TELEGRAM_BOT_TOKEN empty on Iran.
```

Germany (stateless outbound worker):

```dotenv
TELEGRAM_ROLE=worker
# Worker must not require Iran/MySQL sessions or cache.
SESSION_DRIVER=file
CACHE_STORE=file
TELEGRAM_BOT_TOKEN=<from-Telegram-BotFather>
TELEGRAM_WEBHOOK_URL=https://your-germany-server.example/hooks/telegram
TELEGRAM_WEBHOOK_SECRET=<random-32-to-256-char-header-secret>
TELEGRAM_CORE_URL=https://your-iran-server.example
TELEGRAM_GATEWAY_SECRET=<same-random-secret-as-Iran>
```

Generate independent values for `TELEGRAM_WEBHOOK_SECRET` and `TELEGRAM_GATEWAY_SECRET` with `openssl rand -hex 32`. Never commit real tokens/secrets. Run `php artisan config:clear` after entering credentials in each environment. Keep the two secrets **different**.

### Register Telegram webhook (Germany only)

```bash
php artisan telegram:webhook:set
```

This command registers `TELEGRAM_WEBHOOK_URL` with the official `setWebhook` method, `secret_token` and `allowed_updates=["message","my_chat_member"]`. It does **not** run for every notification. Set it up only after both HTTPS endpoints and HMAC credentials are configured. Telegram forwards legitimate updates to Germany; Germany validates the official header and forwards a signed JSON event to Iran.

## Link / disconnect

An authenticated **Iran Hub web session** (CSRF required) uses:

| Method | Endpoint | Function |
| --- | --- | --- |
| POST | /api/v1/channels/telegram/connect | Returns `url` and `expires_in=600` |
| GET | /api/v1/channels/telegram/connection | Returns status |
| DELETE | /api/v1/channels/telegram/connection | Disables and clears the chat ID |

User opens `https://t.me/YourCentralBot?start=<random-token>` and presses **Start**. Telegram delivers `/start <token>` to the central bot. Iran validates the one-time token (10-minute TTL), checks that **both chat and sender refer to the same private user**, and associates that chat with the Hub user. One private chat can only belong to one account. A short welcome confirmation is **queued on Iran** (and sent by the Germany worker) to avoid blocking the webhook round trip. Duplicate or expired tokens cannot reconnect the account.

Disconnect clears the chat ID, invalidates the outstanding link token and cancels queued `pending/retrying` Telegram deliveries for that connection. Telegram `my_chat_member` `kicked` updates and Telegram HTTP 403 mark the connection `blocked`. Reconnection requires another one-time Start link.

## Send from an external project

Use the **existing** REST API with its project API key:

```http
POST /api/v1/notifications
Authorization: Bearer <project-api-key>
Idempotency-Key: <unique-16-to-128-char-key>
Content-Type: application/json

{"title":"CRM reminder","body":"A task is due today","priority":2,"channels":["in_app","telegram"]}
```

No `chat_id` parameter is accepted. The core automatically selects the verified Telegram connection **belonging to the API-key project's owner**. A delivery row is persisted for the channel, and `SendTelegramNotification` queues by the existing five priority levels. Scheduled notifications honor `scheduled_at`.

Status and attempts are returned through the existing `GET /api/v1/notifications/{id}` endpoint; `sent` means **Telegram Bot API accepted the message**, not that the person read it.

Rate limits: the Germany worker enforces up to **25 API sends/sec across the bot** and **1 API send/sec per private chat** with shared cache counters, plus Telegram provider HTTP 429 handling. Existing project API throttling also applies. Iran additionally enforces rolling 24-hour admission limits: default **300 sends per user** and **150 per project**, configurable via `TELEGRAM_USER_DAILY_LIMIT` / `TELEGRAM_PROJECT_DAILY_LIMIT`.

HTTP 429 is retried after `retry_after` with Queue backoff. Chat block (403) disables the connection. Network timeouts, ambiguous 5xx or malformed provider success responses are recorded as `needs_review` **without automatic retry** because the Bot API doesn't provide idempotent `sendMessage`. Retry only after operator review.

## Security and limits

- Telegram's official webhook header `X-Telegram-Bot-Api-Secret-Token` is verified on Germany.
- German outbound commands and forwarded events use `X-NH-Telegram-Time`, `X-NH-Telegram-Nonce` and `X-NH-Telegram-Signature`. Signature: HMAC-SHA256(shared secret, `time + "\\n" + nonce + "\\n" + path + "\\n" + exact JSON body`). Requests expire after 90 seconds and a nonce may be used only once.
- Bot API token is never included in user-facing JSON or logged.
- Only **private chat** starts may connect users; groups, forged sender IDs, arbitrary chat IDs and cross-user claims are rejected.
- Text-only MVP. Maximum 4096 Telegram characters; excess notification content is truncated, and no Markdown/HTML parse mode is used.
- User is not able to read personal chats through the bot.
- A direct authenticated web session is required for linking; no Telegram login/password is collected.
- This is a backend implementation: Hub's account-linking UI has not yet been built.
- No new migration, seeders or third-party packages are required; existing connections and delivery tables are reused.
- Syntax checks / Feature tests / real Bot API integration / Iran–Germany connectivity remain unverified until executed in the target environment.

Official references:
- https://core.telegram.org/bots/features#deep-linking
- https://core.telegram.org/bots/api#setwebhook
- https://core.telegram.org/bots/api#sendmessage
- https://core.telegram.org/bots/api#responseparameters
