import { createApp } from 'vue';
import { VueQueryPlugin } from '@tanstack/vue-query';
import App from './App.vue';
import './assets/design-tokens.css';
import './assets/main.css';

createApp(App).use(VueQueryPlugin).mount('#app');
