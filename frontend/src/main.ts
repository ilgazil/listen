import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useHistoryStore } from '@/stores/history.ts'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Suivi de la position dans l'historique (boutons Précédent / Suivant du header).
router.afterEach(() => useHistoryStore(pinia).track())

app.mount('#app')
