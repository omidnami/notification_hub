<script setup>
import { onMounted, ref } from 'vue';

const devices = ref([]);
const busy = ref(false);
const status = ref('');
const guidance = ref('');
const detected = ref('');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function api(path, options = {}) {
    const response = await fetch(path, {
        ...options,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...(options.headers || {}),
        },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'HTTP ' + response.status);
    return data;
}

async function refreshDevices() {
    try {
        devices.value = (await api('/api/v1/push/devices')).devices || [];
    } catch {
        status.value = 'فهرست دستگاه‌ها دریافت نشد. وارد حساب خود شوید.';
    }
}

function inspectBrowser() {
    const ua = navigator.userAgent;
    const ios = /iPhone|iPad|iPod/i.test(ua) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const android = /Android/i.test(ua);
    const platform = ios ? 'iOS / iPadOS' : android ? 'Android'
        : /Windows/i.test(ua) ? 'Windows'
        : /Linux/i.test(ua) ? 'Linux'
        : /Mac/i.test(ua) ? 'macOS' : 'Other';
    const browser = /Edg\//.test(ua) ? 'Edge'
        : /Firefox\//.test(ua) ? 'Firefox'
        : /Chrome\//.test(ua) ? 'Chrome'
        : /Safari\//.test(ua) ? 'Safari' : 'Other';

    detected.value = platform + ' · ' + browser;

    if (!window.isSecureContext) {
        return { reason: 'اعلان‌ها فقط روی HTTPS (یا localhost) قابل فعال‌سازی هستند.' };
    }

    const standalone = window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true;
    if (ios && !standalone) {
        return {
            reason: 'روی آیفون یا آیپد ابتدا از منوی Share گزینه Add to Home Screen را انتخاب کنید، سپس برنامه را از صفحه اصلی باز کنید و همین دکمه را بزنید.',
        };
    }

    const supported = 'Notification' in window &&
        'serviceWorker' in navigator && 'PushManager' in window;
    if (!supported) {
        return {
            reason: 'این مرورگر یا سیستم‌عامل از Web Push پشتیبانی نمی‌کند. مرورگر به‌روز Chrome، Firefox، Edge یا Safari را بررسی کنید؛ برای iOS از برنامه نصب‌شده در Home Screen استفاده کنید.',
        };
    }
    if (Notification.permission === 'denied') {
        return {
            reason: browser + ': دسترسی اعلان‌ها مسدود شده. از تنظیمات سایت در مرورگر، Notifications را روی Allow بگذارید و دوباره دکمه را بزنید.',
        };
    }

    return { platform, browser };
}

function decodeVapidKey(key) {
    const padded = key.replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded + '='.repeat((4 - padded.length % 4) % 4));
    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

async function enable() {
    if (busy.value) return;

    // The button is always visible. Capability checks happen only after the click.
    status.value = '';
    guidance.value = '';
    const compatibility = inspectBrowser();
    if (compatibility.reason) {
        guidance.value = compatibility.reason;
        return;
    }

    if (Notification.permission !== 'granted') {
        // Request permission immediately in the user's click gesture.
        let permission;
        try {
            permission = await Notification.requestPermission();
        } catch {
            guidance.value = 'مرورگر امکان درخواست مجوز ندارد. تنظیمات اعلان‌های سایت را بررسی کنید.';
            return;
        }
        if (permission !== 'granted') {
            guidance.value = permission === 'denied'
                ? 'اعلان‌ها مسدود شدند. مجوز سایت را در تنظیمات مرورگر تغییر دهید.'
                : 'برای دریافت اعلان باید اجازه دسترسی را صادر کنید.';
            return;
        }
    }

    busy.value = true;
    try {
        const config = await api('/api/v1/push/config');
        if (!config.configured) throw new Error('کلید عمومی VAPID هنوز در سرور تنظیم نشده است.');

        const registration = await navigator.serviceWorker.register('/push-sw.js', { scope: '/' });
        await navigator.serviceWorker.ready;

        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: decodeVapidKey(config.public_key),
            });
        }

        const saved = localStorage.getItem('nh-push-device-id');
        const deviceId = saved || crypto.randomUUID();
        const input = subscription.toJSON();
        const response = await api('/api/v1/push/devices', {
            method: 'POST',
            body: JSON.stringify({
                device_id: deviceId,
                endpoint: input.endpoint,
                keys: input.keys,
                content_encoding: 'aes128gcm',
                device_name: detected.value,
                browser: compatibility.browser,
                platform: compatibility.platform,
            }),
        });

        localStorage.setItem('nh-push-device-id', deviceId);
        await refreshDevices();

        try {
            await api('/api/v1/push/test', {
                method: 'POST',
                body: JSON.stringify({ device_id: response.device_id }),
            });
            status.value = 'اعلان فعال شد و پیام آزمایشی در صف قرار گرفت.';
        } catch {
            status.value = 'اعلان فعال شد؛ ارسال پیام آزمایشی فعلاً امکان‌پذیر نبود.';
        }
    } catch (error) {
        guidance.value = 'فعال‌سازی ناموفق بود: ' + (error?.message || 'خطای نامشخص');
    } finally {
        busy.value = false;
    }
}

async function removeDevice(id) {
    if (busy.value) return;
    busy.value = true;
    try {
        await api('/api/v1/push/devices/' + id, { method: 'DELETE' });
        await refreshDevices();
        status.value = 'دستگاه از فهرست اعلان‌ها حذف شد.';
    } catch (error) {
        status.value = error.message;
    } finally {
        busy.value = false;
    }
}

onMounted(refreshDevices);
</script>

<template>
    <section class="mx-auto max-w-2xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm" dir="rtl">
        <h1 class="text-xl font-bold text-slate-900">اعلان‌های مرورگر</h1>
        <p class="mt-2 text-sm text-slate-600">
            اعلان‌ها را روی موبایل و کامپیوتر دریافت کنید؛ هر دستگاه جداگانه مدیریت می‌شود.
        </p>

        <!-- Never conditionally hide the activation button. -->
        <button type="button" :disabled="busy" @click="enable"
            class="mt-5 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">
            {{ busy ? 'در حال فعال‌سازی…' : 'فعال‌سازی اعلان‌ها' }}
        </button>

        <p v-if="detected" class="mt-3 text-xs text-slate-500">دستگاه شناسایی‌شده: {{ detected }}</p>
        <p v-if="status" role="status" class="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">
            {{ status }}
        </p>
        <p v-if="guidance" role="alert" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
            {{ guidance }}
        </p>

        <div class="mt-8">
            <h2 class="font-semibold text-slate-900">دستگاه‌های من</h2>
            <p v-if="devices.length === 0" class="mt-2 text-sm text-slate-500">هنوز دستگاهی ثبت نشده است.</p>
            <ul v-else class="mt-3 space-y-2">
                <li v-for="device in devices" :key="device.id"
                    class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">{{ device.device_name || 'دستگاه' }}</div>
                        <div class="text-xs text-slate-500">{{ device.status }} · {{ device.browser || 'Browser' }}</div>
                    </div>
                    <button type="button" :disabled="busy" @click="removeDevice(device.id)"
                        class="shrink-0 rounded-md border border-rose-300 px-3 py-1 text-xs text-rose-700 disabled:opacity-50">
                        حذف دستگاه
                    </button>
                </li>
            </ul>
        </div>
    </section>
</template>
