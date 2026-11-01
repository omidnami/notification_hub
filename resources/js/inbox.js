import { createApp } from 'vue';
import NotificationInbox from './components/NotificationInbox.vue';

const el = document.getElementById('notification-inbox');
if (el) createApp(NotificationInbox).mount(el);
