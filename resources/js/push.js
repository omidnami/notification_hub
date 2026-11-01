import { createApp } from 'vue';
import PushActivation from './components/PushActivation.vue';

const root = document.getElementById('push-activation');
if (root) createApp(PushActivation).mount(root);
