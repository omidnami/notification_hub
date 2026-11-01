# Bale channel

Backend-only central bot, using the official Bale Bot API.

1. Create the bot with @botfather in Bale.
2. Set BALE_BOT_TOKEN, BALE_BOT_USERNAME, BALE_WEBHOOK_SECRET (random 32+ alphanumeric/underscore/dash), and an HTTPS APP_URL.
3. Run the new connection-index migration on the development database.
4. Run php artisan bale:webhook:set after deploying a publicly accessible HTTPS endpoint.
5. An authenticated Hub user POSTs /api/v1/channels/bale/connect (web session + CSRF), receives a one-time 10-minute deep link, and starts the bot.
6. POST /hooks/bale/{secret} receives the /start token, links only a private chat, and sends a welcome message.
7. The notification API layer can call app(\App\Services\BaleService::class)->queueDelivery($notificationId, $connectionId); it dispatches SendBaleNotification to the priority queue, recording a row per connection.

GET /api/v1/channels/bale/connection reads status; DELETE /api/v1/channels/bale/connection disables delivery.

No real bot token or database changes are included in this commit. Run workers with the relevant five priority queues. The notification creation API and login UI remain separate work.
