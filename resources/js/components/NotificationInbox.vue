<script setup>
import { onMounted, onUnmounted, reactive, ref } from 'vue';

const filters = reactive({ q: '', project_id: '', priority: '', read: '', from: '', to: '' });
const items = ref([]);
const meta = ref({ current_page: 1, last_page: 1 });
const busy = ref(false);
const error = ref('');
const selected = ref(null);
const deliveries = ref([]);
let stream = null;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function request(path, options = {}) {
    const response = await fetch(path, {
        credentials: 'same-origin', ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...(options.headers || {}),
        },
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(json.message || 'خطای درخواست');
    return json;
}

async function load(page = 1) {
    busy.value = true;
    error.value = '';
    try {
        const query = new URLSearchParams({ page: String(page) });
        for (const [key, value] of Object.entries(filters)) {
            if (value !== '') query.set(key, String(value));
        }
        const json = await request('/api/v1/inbox?' + query.toString());
        items.value = json.data || [];
        meta.value = { current_page: json.current_page, last_page: json.last_page };
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

async function open(item) {
    try {
        const data = await request('/api/v1/inbox/' + item.id);
        selected.value = data.notification;
        deliveries.value = data.deliveries || [];
    } catch (e) { error.value = e.message; }
}

async function markRead(item) {
    try {
        await request('/api/v1/inbox/' + item.id + '/read', { method: 'PATCH' });
        item.read_at = new Date().toISOString();
        if (selected.value?.id === item.id) selected.value.read_at = item.read_at;
    } catch (e) { error.value = e.message; }
}

async function remove(item) {
    if (!confirm('این اعلان از صندوق شما حذف شود؟')) return;
    try {
        await request('/api/v1/inbox/' + item.id, { method: 'DELETE' });
        items.value = items.value.filter((row) => row.id !== item.id);
        if (selected.value?.id === item.id) selected.value = null;
    } catch (e) { error.value = e.message; }
}

function subscribe() {
    if (!window.EventSource) {
        error.value = 'این مرورگر از SSE پشتیبانی نمی‌کند. می‌توانید صفحه را تازه‌سازی کنید.';
        return;
    }
    const cursor = items.value.reduce((id, item) => Math.max(id, Number(item.id)), 0);
    stream = new EventSource('/api/v1/inbox/stream' + (cursor ? '?last_id=' + cursor : ''));
    stream.addEventListener('notification', (event) => {
        let data;
        try { data = JSON.parse(event.data); } catch { return; }
        if (meta.value.current_page === 1 && Object.values(filters).every((value) => value === '')) {
            if (!items.value.some((row) => row.id === data.id)) {
                items.value.unshift({ ...data, read_at: null, body: null });
                items.value = items.value.slice(0, 20);
            }
        } else {
            // Filters are active: refetch to preserve correct filtered results.
            load(meta.value.current_page);
        }
    });
}

onMounted(async () => { await load(); subscribe(); });
onUnmounted(() => stream?.close());
</script>

<template>
    <main dir="rtl" class="mx-auto max-w-5xl rounded-xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold">مرکز اعلان‌ها</h1>
            <a href="/settings/notifications" class="text-sm text-emerald-700 underline">تنظیمات اعلان مرورگر</a>
        </div>
        <form @submit.prevent="load(1)" class="mt-5 grid gap-3 sm:grid-cols-3">
            <input v-model="filters.q" placeholder="جست‌وجو در اعلان‌ها" class="rounded-md border p-2 text-sm">
            <input v-model="filters.project_id" inputmode="numeric" placeholder="شناسه پروژه" class="rounded-md border p-2 text-sm">
            <select v-model="filters.priority" class="rounded-md border p-2 text-sm">
                <option value="">همه اهمیت‌ها</option>
                <option v-for="n in 5" :key="n" :value="String(n)">اولویت {{ n }}</option>
            </select>
            <select v-model="filters.read" class="rounded-md border p-2 text-sm">
                <option value="">خوانده‌شده و خوانده‌نشده</option>
                <option value="0">خوانده‌نشده</option>
                <option value="1">خوانده‌شده</option>
            </select>
            <input v-model="filters.from" type="date" class="rounded-md border p-2 text-sm">
            <input v-model="filters.to" type="date" class="rounded-md border p-2 text-sm">
            <button type="submit" class="rounded-md bg-emerald-700 p-2 text-sm text-white">اعمال فیلتر</button>
        </form>
        <p v-if="error" role="alert" class="my-3 rounded bg-rose-50 p-3 text-sm text-rose-800">{{ error }}</p>
        <p v-if="busy" class="my-4 text-sm text-slate-500">در حال دریافت…</p>
        <div class="mt-4 divide-y">
            <article v-for="item in items" :key="item.id" class="flex gap-3 py-3">
                <span :class="item.read_at ? 'bg-slate-300' : 'bg-emerald-500'"
                    class="mt-2 h-2 w-2 shrink-0 rounded-full"></span>
                <div class="min-w-0 flex-1">
                    <button class="text-right font-semibold" type="button" @click="open(item)">{{ item.title }}</button>
                    <p class="text-sm text-slate-600">{{ item.body }}</p>
                    <div class="text-xs text-slate-500">پروژه {{ item.project_id || 'سیستم' }} · اولویت {{ item.priority }} · {{ item.created_at }}</div>
                </div>
                <div class="flex shrink-0 flex-col gap-1 text-xs">
                    <button v-if="!item.read_at" type="button" @click="markRead(item)" class="text-emerald-700">خواندم</button>
                    <button type="button" @click="remove(item)" class="text-rose-600">حذف</button>
                </div>
            </article>
            <p v-if="!busy && items.length === 0" class="py-8 text-center text-sm text-slate-500">اعلانی یافت نشد.</p>
        </div>
        <div class="mt-4 flex justify-between text-sm">
            <button :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">قبلی</button>
            <span>صفحه {{ meta.current_page }} از {{ meta.last_page }}</span>
            <button :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">بعدی</button>
        </div>
        <div v-if="selected" class="mt-6 rounded-lg border bg-slate-50 p-4">
            <div class="flex justify-between gap-3"><h2 class="font-bold">{{ selected.title }}</h2><button @click="selected = null">بستن</button></div>
            <p class="mt-3 whitespace-pre-wrap text-sm">{{ selected.body }}</p>
            <h3 class="mt-5 font-semibold">تاریخچه ارسال</h3>
            <div v-for="(delivery, index) in deliveries" :key="index" class="mt-2 border-b pb-2 text-sm">
                {{ delivery.channel }} · {{ delivery.status }} · تلاش {{ delivery.attempts }}
                <p v-if="delivery.last_error" class="text-rose-700">{{ delivery.last_error }}</p>
            </div>
            <p v-if="deliveries.length === 0" class="mt-2 text-xs text-slate-500">فقط اعلان داخلی ثبت شده است.</p>
        </div>
    </main>
</template>
