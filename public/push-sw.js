/* Notification Hub service worker: never execute HTML from push payloads. */
self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { title: 'Notification Hub', body: 'اعلان جدید' };
    }

    const safePath = (value, fallback) =>
        typeof value === 'string' && value.startsWith('/') &&
        !value.startsWith('//') && !value.includes('\\') ? value : fallback;
    const title = typeof payload.title === 'string' ? payload.title : 'Notification Hub';
    const options = {
        body: typeof payload.body === 'string' ? payload.body : '',
        icon: safePath(payload.icon, '/push-icon.svg'),
        tag: typeof payload.tag === 'string' ? payload.tag : undefined,
        data: { url: safePath(payload.url, '/') },
    };

    // iOS requires each received push to display a visible notification.
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const path = event.notification.data?.url || '/';
    const url = new URL(path, self.location.origin);
    if (url.origin !== self.location.origin) return;

    event.waitUntil((async () => {
        const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (client.url === url.href && 'focus' in client) return client.focus();
        }
        return clients.openWindow(url.href);
    })());
});
