# Rubika official Bot API

One central Rubika bot for all Hub users; no third-party intermediary.

## Configuration

1. Create the bot via Rubika @BotFather.
2. Set RUBIKA_BOT_TOKEN, RUBIKA_BOT_USERNAME, RUBIKA_WEBHOOK_SECRET (32+ random alphanumeric characters), and an HTTPS APP_URL.
3. Deploy a publicly reachable HTTPS webhook and run `php artisan rubika:webhook:set` to register the ReceiveUpdate endpoint.

## User connection

- Authenticated POST /api/v1/channels/rubika/connect returns a 10-minute single-use link: `https://rubika.ir/<botusername>?st=<token>`.
- Rubika reports the `new_message.aux_data.start_id` in the webhook; the service confirms `getChat.chat_type = User`, then associates that chat with the Hub user.
- GET /api/v1/channels/rubika/connection returns status.
- DELETE /api/v1/channels/rubika/connection disables the connection.
- StoppedBot events mark existing connections as blocked.

## Queue delivery

Use `app(\App\Services\RubikaService::class)->queueDelivery($notificationId, $connectionId)`.
This records a row in notification_deliveries and dispatches SendRubikaNotification on one of five priority queues. Delivery results are stored per channel. The Hub notification creation API and authentication UI remain separate tasks.

No live calls were performed. Do not commit real bot tokens. Docs: https://rubika.ir/botapi.
